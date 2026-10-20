<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Command;

use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Validation\ListValidator;
use Sidus\FilterBundle\Exception\UnknownListException;
use Sidus\FilterBundle\Registry\ListDefinitionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'lint:sidus-filter', description: 'Validates the doctrine lists (field paths, criteria, sorts) against the Doctrine metadata')]
final class LintCommand extends Command
{
    public function __construct(
        private readonly ListDefinitionRegistry $definitions,
        private readonly ListValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('code', InputArgument::OPTIONAL, 'Only this list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = $input->getArgument('code');
        try {
            $codes = null === $code ? $this->definitions->codes() : [$this->definitions->get($code)->code];
        } catch (UnknownListException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $checked = 0;
        $errors = [];
        foreach ($codes as $listCode) {
            $list = $this->definitions->get($listCode);
            if (DoctrineQueryHandlerFactory::PROVIDER !== $list->provider) {
                continue;
            }
            ++$checked;
            array_push($errors, ...$this->validator->validate($list));
        }

        if ([] !== $errors) {
            $io->error(\sprintf('%d problem(s) in %d doctrine list(s):', \count($errors), $checked));
            $io->listing($errors);

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d doctrine list(s) match the Doctrine metadata.', $checked));

        return Command::SUCCESS;
    }
}
