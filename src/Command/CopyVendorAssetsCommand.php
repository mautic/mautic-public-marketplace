<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:assets:copy',
    description: 'Copy vendor assets (e.g. Carbon pictograms, icons) from node_modules to public/assets',
)]
final class CopyVendorAssetsCommand extends Command
{
    private const ASSET_SETS = [
        'pictograms' => [
            'source' => 'node_modules/@carbon/pictograms/svg',
            'target' => 'public/assets/pictograms',
        ],
    ];

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'set',
            InputArgument::OPTIONAL,
            'Asset set to copy: '.implode(', ', array_keys(self::ASSET_SETS)).', or omit to copy all',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $set = $input->getArgument('set');

        if (null !== $set) {
            if (!isset(self::ASSET_SETS[$set])) {
                $io->error(\sprintf('Unknown set "%s". Available: %s', $set, implode(', ', array_keys(self::ASSET_SETS))));

                return Command::FAILURE;
            }
            $sets = [$set => self::ASSET_SETS[$set]];
        } else {
            $sets = self::ASSET_SETS;
        }

        $filesystem = new Filesystem();
        $projectDir = $this->kernel->getProjectDir();

        foreach ($sets as $name => $config) {
            $source = $projectDir.'/'.$config['source'];
            $target = $projectDir.'/'.$config['target'];

            if (!$filesystem->exists($source)) {
                $io->warning(\sprintf('%s: source not found at %s. Run: npm install', $name, $config['source']));
                continue;
            }

            $filesystem->mirror($source, $target, null, ['override' => true, 'delete' => true]);
            $io->success(\sprintf('%s copied to %s', $name, $target));
        }

        return Command::SUCCESS;
    }
}
