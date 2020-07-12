<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CloudPatches\Command\Process;

use Magento\CloudPatches\App\RuntimeException;
use Magento\CloudPatches\Patch\Pool\RequiredPool;
use Magento\CloudPatches\Patch\Applier;
use Magento\CloudPatches\Patch\ApplierException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\CloudPatches\Patch\ConflictAnalyzer\Required as ConflictAnalyzer;

/**
 * Applies required patches (Cloud only).
 *
 * Patches are applying from top to bottom of config list.
 */
class ApplyRequired implements ProcessInterface
{
    /**
     * @var Applier
     */
    private $applier;

    /**
     * @var RequiredPool
     */
    private $requiredPool;

    /**
     * @var Renderer
     */
    private $renderer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConflictAnalyzer
     */
    private $conflictAnalyzer;

    /**
     * @param Applier $applier
     * @param RequiredPool $requiredPool
     * @param Renderer $renderer
     * @param LoggerInterface $logger
     * @param ConflictAnalyzer $conflictAnalyzer
     */
    public function __construct(
        Applier $applier,
        RequiredPool $requiredPool,
        Renderer $renderer,
        LoggerInterface $logger,
        ConflictAnalyzer $conflictAnalyzer
    ) {
        $this->applier = $applier;
        $this->requiredPool = $requiredPool;
        $this->renderer = $renderer;
        $this->logger = $logger;
        $this->conflictAnalyzer = $conflictAnalyzer;
    }

    /**
     * @inheritDoc
     */
    public function run(InputInterface $input, OutputInterface $output)
    {
        $this->logger->notice('Start of applying required patches');

        $appliedPatches = [];
        $patches = $this->requiredPool->getList();
        foreach ($patches as $patch) {
            try {
                $message = $this->applier->apply($patch->getPath(), $patch->getId());
                $this->renderer->printPatchInfo($output, $patch, $message);
                $this->logger->info($message, ['file' => $patch->getPath()]);
                array_push($appliedPatches, $patch);
            } catch (ApplierException $exception) {
                $this->logger->error('Conflict happened');
                $conflictDetails = $this->conflictAnalyzer->analyze($patch->getId());
                $errorMessage = sprintf(
                    '<error>Applying patch %s (%s) failed.%s%s</error>',
                    $patch->getId(),
                    $patch->getPath(),
                    $this->renderer->formatErrorOutput($exception->getMessage()),
                    $conflictDetails ? PHP_EOL . $conflictDetails : ''
                );

                throw new RuntimeException($errorMessage, $exception->getCode());
            }
        }

        $this->logger->notice('End of applying required patches');
    }
}
