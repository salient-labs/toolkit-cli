<?php declare(strict_types=1);

namespace Salient\Cli;

use Lkrms\Console\Support\ConsoleManPageFormat;
use Lkrms\Console\Support\ConsoleMarkdownFormat;
use Lkrms\Console\ConsoleFormatter as Formatter;
use Lkrms\Contract\HasJsonSchema;
use Lkrms\Facade\Console;
use Salient\Cli\Catalog\CliHelpSectionName;
use Salient\Cli\Catalog\CliHelpTarget;
use Salient\Cli\Contract\CliApplicationInterface;
use Salient\Cli\Contract\CliCommandInterface;
use Salient\Cli\Exception\CliInvalidArgumentsException;
use Salient\Container\Application;
use Salient\Core\Catalog\EnvFlag;
use Salient\Core\Utility\Arr;
use Salient\Core\Utility\Assert;
use Salient\Core\Utility\Get;
use Salient\Core\Utility\Json;
use Salient\Core\Utility\Package;
use Salient\Core\Utility\Pcre;
use Salient\Core\Utility\Str;
use Salient\Core\Utility\Sys;
use LogicException;

/**
 * A service container for CLI applications
 */
class CliApplication extends Application implements CliApplicationInterface
{
    private const COMMAND_REGEX = '/^[a-z][a-z0-9_-]*$/iD';

    /**
     * @var array<string,class-string<CliCommandInterface>|mixed[]>
     */
    private $CommandTree = [];

    private ?CliCommandInterface $RunningCommand = null;

    private ?CliCommandInterface $LastCommand = null;

    private int $LastExitStatus = 0;

    /**
     * @inheritDoc
     */
    public function __construct(
        ?string $basePath = null,
        ?string $appName = null,
        int $envFlags = EnvFlag::ALL,
        ?string $configDir = 'config'
    ) {
        parent::__construct($basePath, $appName, $envFlags, $configDir);

        Assert::runningOnCli();

        Assert::argvIsDeclared();

        // Keep running, even if:
        // - the TTY disconnects
        // - `max_execution_time` is non-zero
        // - `memory_limit` is exceeded
        ignore_user_abort(true);
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        // Exit cleanly when interrupted
        Sys::handleExitSignals();
    }

    /**
     * @inheritDoc
     */
    public function getProgramName(): string
    {
        return Sys::getProgramBasename();
    }

    /**
     * @inheritDoc
     */
    public function getRunningCommand(): ?CliCommandInterface
    {
        return $this->RunningCommand;
    }

    /**
     * @inheritDoc
     */
    public function getLastCommand(): ?CliCommandInterface
    {
        return $this->LastCommand;
    }

    /**
     * @inheritDoc
     */
    public function getLastExitStatus(): int
    {
        return $this->LastExitStatus;
    }

    /**
     * Get a command instance from the given node in the command tree
     *
     * Returns `null` if no command is registered at the given node.
     *
     * @param string $name The name of the node as a space-delimited list of
     * subcommands.
     * @param array<string,class-string<CliCommandInterface>|mixed[]>|class-string<CliCommandInterface>|false|null $node The node as returned by {@see CliApplication::getNode()}.
     */
    protected function getNodeCommand(string $name, $node): ?CliCommandInterface
    {
        if (!is_string($node)) {
            return null;
        }

        if (!(($command = $this->get($node)) instanceof CliCommandInterface)) {
            throw new LogicException(sprintf(
                'Does not implement %s: %s',
                CliCommandInterface::class,
                $node,
            ));
        }
        $command->setName($name ? explode(' ', $name) : []);

        return $command;
    }

    /**
     * Resolve an array of subcommand names to a node in the command tree
     *
     * Returns one of the following:
     * - `null` if nothing has been added to the tree at `$name`
     * - the name of the {@see CliCommandInterface} class registered at `$name`
     * - an array that maps subcommands of `$name` to their respective nodes
     * - `false` if a {@see CliCommandInterface} has been registered above
     *   `$name`, e.g. if `$name` is `["sync", "canvas", "from-sis"]` and a
     *   command has been registered at `["sync", "canvas"]`
     *
     * Nodes in the command tree are either subcommand arrays (branches) or
     * {@see CliCommandInterface} class names (leaves).
     *
     * @param string[] $name
     * @return array<string,class-string<CliCommandInterface>|mixed[]>|class-string<CliCommandInterface>|false|null
     */
    protected function getNode(array $name = [])
    {
        $tree = $this->CommandTree;

        foreach ($name as $subcommand) {
            if ($tree === null) {
                return null;
            } elseif (!is_array($tree)) {
                return false;
            }

            $tree = $tree[$subcommand] ?? null;
        }

        return $tree ?: null;
    }

    /**
     * @inheritDoc
     */
    public function oneCommand(string $id)
    {
        return $this->command([], $id);
    }

    /**
     * @inheritDoc
     */
    public function command(array $name, string $id)
    {
        foreach ($name as $subcommand) {
            if (!Pcre::match(self::COMMAND_REGEX, $subcommand)) {
                throw new LogicException(sprintf(
                    'Subcommand does not start with a letter, followed by zero or more letters, numbers, hyphens or underscores: %s',
                    $subcommand,
                ));
            }
        }

        if ($this->getNode($name) !== null) {
            throw new LogicException("Another command has been registered at '" . implode(' ', $name) . "'");
        }

        $tree = &$this->CommandTree;
        $branch = $name;
        $leaf = array_pop($branch);

        foreach ($branch as $subcommand) {
            if (!is_array($tree[$subcommand] ?? null)) {
                $tree[$subcommand] = [];
            }

            $tree = &$tree[$subcommand];
        }

        if ($leaf !== null) {
            $tree[$leaf] = $id;
        } else {
            $tree = $id;
        }

        return $this;
    }

    /**
     * Get a help message for a command tree node
     *
     * @param array<string,class-string<CliCommandInterface>|mixed[]>|class-string<CliCommandInterface> $node
     */
    private function getHelp(string $name, $node, ?CliHelpStyle $style = null): ?string
    {
        $style ??= new CliHelpStyle(CliHelpTarget::NORMAL);

        $command = $this->getNodeCommand($name, $node);
        if ($command) {
            return $style->buildHelp($command->getHelp($style));
        }

        if (!is_array($node)) {
            return null;
        }

        $progName = $this->getProgramName();
        $fullName = trim("$progName $name");
        $synopses = [];
        foreach ($node as $childName => $childNode) {
            $command = $this->getNodeCommand(trim("$name $childName"), $childNode);
            if ($command) {
                $synopses[] = '__' . $childName . '__ - ' . Formatter::escapeTags($command->description());
            } elseif (is_array($childNode)) {
                $synopses[] = '__' . $childName . '__';
            }
        }

        return $style->buildHelp([
            CliHelpSectionName::NAME => $fullName,
            CliHelpSectionName::SYNOPSIS => '__' . $fullName . '__ <command>',
            'SUBCOMMANDS' => implode("\n", $synopses),
        ]);
    }

    /**
     * Get usage information for a command tree node
     *
     * @param array<string,class-string<CliCommandInterface>|mixed[]>|class-string<CliCommandInterface> $node
     */
    private function getUsage(string $name, $node): ?string
    {
        $style = new CliHelpStyle(CliHelpTarget::PLAIN, CliHelpStyle::getConsoleWidth());

        $command = $this->getNodeCommand($name, $node);
        $progName = $this->getProgramName();

        if ($command) {
            return $command->getSynopsis($style)
                . Formatter::escapeTags("\n\nSee '"
                    . ($name === '' ? "$progName --help" : "$progName help $name")
                    . "' for more information.");
        }

        if (!is_array($node)) {
            return null;
        }

        $style = $style->withCollapseSynopsis();
        $fullName = trim("$progName $name");
        $synopses = [];
        foreach ($node as $childName => $childNode) {
            $command = $this->getNodeCommand(trim("$name $childName"), $childNode);
            if ($command) {
                $synopsis = $command->getSynopsis($style);
            } elseif (is_array($childNode)) {
                $synopsis = "$fullName $childName <command>";
                $synopsis = Formatter::escapeTags($synopsis);
            } else {
                continue;
            }
            $synopses[] = $synopsis;
        }

        return implode("\n", $synopses)
            . Formatter::escapeTags("\n\nSee '"
                . Arr::implode(' ', ["$progName help", $name, '<command>'])
                . "' for more information.");
    }

    /**
     * @inheritDoc
     */
    public function run()
    {
        $this->LastExitStatus = 0;

        $args = array_slice($_SERVER['argv'], 1);

        $lastNode = null;
        $lastName = null;
        $node = $this->CommandTree;
        $name = '';

        while (is_array($node)) {
            $arg = array_shift($args);

            // Print usage info if the last remaining $arg is "--help"
            if ($arg === '--help' && !$args) {
                $usage = $this->getHelp($name, $node);
                Console::stdout($usage);
                return $this;
            }

            // or version number if it's "--version"
            if ($arg === '--version' && !$args) {
                $appName = $this->getAppName();
                $version = Package::version(true, true);
                Console::stdout('__' . $appName . '__ ' . $version);
                return $this;
            }

            // - If $args was empty before this iteration, print terse usage
            //   info and exit without error
            // - If $arg cannot be a valid subcommand, print terse usage info
            //   and return a non-zero exit status
            if (
                $arg === null ||
                !Pcre::match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $arg)
            ) {
                $usage = $this->getUsage($name, $node);
                Console::out($usage);
                $this->LastExitStatus =
                    $arg === null
                        ? 0
                        : 1;
                return $this;
            }

            // Descend into the command tree if $arg is a registered subcommand
            // or an unambiguous abbreviation thereof
            $nodes = [];
            foreach ($node as $childName => $childNode) {
                if (strpos($childName, $arg) === 0) {
                    $nodes[$childName] = $childNode;
                }
            }
            switch (count($nodes)) {
                case 0:
                    // Push "--help" onto $args and continue if $arg is "help"
                    // or an abbreviation of "help"
                    if (strpos('help', $arg) === 0) {
                        $args[] = '--help';
                        continue 2;
                    }
                    break;
                case 1:
                    // Expand unambiguous subcommands to their full names
                    $arg = array_key_first($nodes);
                    break;
            }
            $lastNode = $node;
            $lastName = $name;
            $node = $node[$arg] ?? null;
            $name .= ($name === '' ? '' : ' ') . $arg;
        }

        if ($args && $args[0] === '_md') {
            array_shift($args);
            $this->generateHelp($name, $node, CliHelpTarget::MARKDOWN, ...$args);
            return $this;
        }

        if ($args && $args[0] === '_man') {
            array_shift($args);
            $this->generateHelp($name, $node, CliHelpTarget::MAN_PAGE, ...$args);
            return $this;
        }

        $command = $this->getNodeCommand($name, $node);

        try {
            if (!$command) {
                throw new CliInvalidArgumentsException(
                    sprintf('no command registered: %s', $name)
                );
            }

            if ($args && $args[0] === '_json_schema') {
                array_shift($args);
                $schema = $command->getJsonSchema();
                echo Json::prettyPrint([
                    '$schema' => $schema['$schema'] ?? HasJsonSchema::DRAFT_04_SCHEMA_ID,
                    'title' => $args[0] ?? $schema['title'] ?? trim($this->getProgramName() . " $name") . ' options',
                ] + $schema) . \PHP_EOL;
                return $this;
            }

            $this->RunningCommand = $command;
            $this->LastExitStatus = $command(...$args);
            return $this;
        } catch (CliInvalidArgumentsException $ex) {
            $ex->reportErrors();
            if (!$node) {
                $node = $lastNode;
                $name = $lastName;
            }
            if (
                $node &&
                ($usage = $this->getUsage($name, $node)) !== null
            ) {
                Console::out("\n" . $usage);
            }
            $this->LastExitStatus = 1;
            return $this;
        } finally {
            $this->RunningCommand = null;
            if ($command !== null) {
                $this->LastCommand = $command;
            }
        }
    }

    /**
     * @inheritDoc
     *
     * @codeCoverageIgnore
     */
    public function exit()
    {
        exit($this->LastExitStatus);
    }

    /**
     * @inheritDoc
     *
     * @codeCoverageIgnore
     */
    public function runAndExit()
    {
        $this->run()->exit();
    }

    /**
     * @param array<string,class-string<CliCommandInterface>|mixed[]>|class-string<CliCommandInterface> $node
     * @param int&CliHelpTarget::* $target
     */
    private function generateHelp(string $name, $node, int $target, string ...$args): void
    {
        $collapseSynopsis = null;

        switch ($target) {
            case CliHelpTarget::MARKDOWN:
                $formats = ConsoleMarkdownFormat::getTagFormats();
                $collapseSynopsis = Get::boolean($args[0] ?? null);
                break;

            case CliHelpTarget::MAN_PAGE:
                $formats = ConsoleManPageFormat::getTagFormats();
                $progName = $this->getProgramName();
                printf(
                    '%% %s(%d) %s | %s%s',
                    Str::upper(str_replace(' ', '-', trim("$progName $name"))),
                    (int) ($args[0] ?? '1'),
                    $args[1] ?? Package::version(),
                    $args[2] ?? (($name === '' ? $progName : Package::name()) . ' Documentation'),
                    \PHP_EOL . \PHP_EOL,
                );
                break;

            default:
                throw new LogicException(sprintf('Invalid CliHelpTarget: %d', $target));
        }

        $formatter = new Formatter($formats, null, fn(): int => 80);
        $style = new CliHelpStyle($target, 80, $formatter);

        if ($collapseSynopsis !== null) {
            $style = $style->withCollapseSynopsis($collapseSynopsis);
        }

        $usage = $this->getHelp($name, $node, $style);
        $usage = $formatter->formatTags($usage);
        $usage = Str::eolToNative($usage);
        printf('%s%s', str_replace('\ ', "\u{00A0}", $usage), \PHP_EOL);
    }
}
