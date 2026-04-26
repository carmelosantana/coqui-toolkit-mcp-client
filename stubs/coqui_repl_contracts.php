<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer {
    if (!class_exists(AnimatedTickCallback::class)) {
        final class AnimatedTickCallback
        {
            public function start(string $context = ''): void
            {
            }

            public function stop(): void
            {
            }

            public function suspend(): void
            {
            }

            public function resume(): void
            {
            }

            public function setContext(string $context): void
            {
            }

            public function tick(): void
            {
            }
        }
    }
}

namespace CoquiBot\Coqui\Repl {
    if (!class_exists(InterruptiblePrompt::class)) {
        final class InterruptiblePrompt
        {
            public function ask(string $question, ?string $default = null): ?string
            {
                return $default;
            }

            public function askHidden(string $question): ?string
            {
                return null;
            }

            public function confirm(string $question, bool $default = false): bool
            {
                return $default;
            }

            /**
             * @param array<int|string, string> $choices
             */
            public function choice(string $question, array $choices, ?string $default = null): string
            {
                return $default ?? (string) array_key_first($choices);
            }
        }
    }
}

namespace CoquiBot\Coqui\Support {
    if (!class_exists(ToolkitDatabaseFactory::class)) {
        final readonly class ToolkitDatabaseFactory
        {
            public function __construct(
                private string $workspacePath,
            ) {}

            public function open(string $name): \PDO
            {
                return new \PDO('sqlite::memory:');
            }
        }
    }
}

namespace CoquiBot\Coqui\Contract {
    use CoquiBot\Coqui\Observer\AnimatedTickCallback;
    use CoquiBot\Coqui\Repl\InterruptiblePrompt;
    use CoquiBot\Coqui\Support\ToolkitDatabaseFactory;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;

    if (!interface_exists(ReplCommandProvider::class)) {
        interface ReplCommandProvider
        {
            /**
             * @return list<ToolkitCommandHandler>
             */
            public function commandHandlers(): array;
        }
    }

    if (!class_exists(ToolkitReplContext::class)) {
        final readonly class ToolkitReplContext
        {
            public function __construct(
                public SymfonyStyle $io,
                public InterruptiblePrompt $prompt,
                public string $workspacePath,
                public ?string $activeProfile,
                public string $sessionId,
                private OutputInterface $output,
                private ToolkitDatabaseFactory $databaseFactory,
            ) {}

            public function createSpinner(string $context = ''): AnimatedTickCallback
            {
                $spinner = new AnimatedTickCallback();
                if ($context !== '') {
                    $spinner->setContext($context);
                }

                return $spinner;
            }

            public function openDatabase(string $name): \PDO
            {
                return $this->databaseFactory->open($name);
            }
        }
    }

    if (!interface_exists(ToolkitCommandHandler::class)) {
        interface ToolkitCommandHandler
        {
            public function commandName(): string;

            /**
             * @return list<string>
             */
            public function subcommands(): array;

            public function usage(): string;

            public function description(): string;

            public function handle(ToolkitReplContext $context, string $arg): void;
        }
    }

    if (!interface_exists(ToolkitTabCompletionProvider::class)) {
        interface ToolkitTabCompletionProvider
        {
            /**
             * @param list<string> $parts
             * @return list<string>
             */
            public function completeArguments(string $commandName, array $parts): array;
        }
    }

    if (!class_exists(ToolkitCommandExample::class)) {
        final readonly class ToolkitCommandExample
        {
            public function __construct(
                public string $command,
                public string $description = '',
            ) {}
        }
    }

    if (!class_exists(ToolkitCommandHelpEntry::class)) {
        final readonly class ToolkitCommandHelpEntry
        {
            public function __construct(
                public string $name,
                public string $usage,
                public string $description,
            ) {}
        }
    }

    if (!class_exists(ToolkitCommandHelp::class)) {
        final readonly class ToolkitCommandHelp
        {
            /**
             * @param list<ToolkitCommandHelpEntry> $subcommands
             * @param list<ToolkitCommandExample> $examples
             * @param list<string> $notes
             */
            public function __construct(
                public ?string $title = null,
                public ?string $summary = null,
                public array $subcommands = [],
                public array $examples = [],
                public array $notes = [],
            ) {}
        }
    }

    if (!interface_exists(ToolkitCommandHelpProvider::class)) {
        interface ToolkitCommandHelpProvider
        {
            public function help(): ToolkitCommandHelp;
        }
    }
}