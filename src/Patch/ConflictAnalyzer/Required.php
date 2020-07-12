<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CloudPatches\Patch\ConflictAnalyzer;

use Magento\CloudPatches\Filesystem\Filesystem;
use Magento\CloudPatches\Patch\Applier;
use Magento\CloudPatches\Patch\ApplierException;
use Magento\CloudPatches\Patch\Data\PatchInterface;
use Magento\CloudPatches\Patch\Environment;
use Magento\CloudPatches\Patch\Pool\OptionalPool;
use Magento\CloudPatches\Patch\Pool\RequiredPool;
use Psr\Log\LoggerInterface;

/**
 * Analyzes required patch conflicts.
 */
class Required
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
     * @var Environment
     */
    private $environment;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var OptionalPool
     */
    private $optionalPool;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Applier $applier
     * @param RequiredPool $requiredPool
     * @param Environment $environment
     * @param Filesystem $filesystem
     * @param OptionalPool $optionalPool
     * @param LoggerInterface $logger
     */
    public function __construct(
        Applier $applier,
        RequiredPool $requiredPool,
        Environment $environment,
        Filesystem $filesystem,
        OptionalPool $optionalPool,
        LoggerInterface $logger
    ) {
        $this->applier = $applier;
        $this->requiredPool = $requiredPool;
        $this->environment = $environment;
        $this->filesystem = $filesystem;
        $this->optionalPool = $optionalPool;
        $this->logger = $logger;
    }

    /**
     * Returns details about conflict with required patch.
     *
     * @param string $failedPatchId
     * @return string
     */
    public function analyze(string $failedPatchId): string
    {
        $this->cleanupInstance();
        $requiredPatchIds = $this->getRequiredIds();
        $poolToCompare = array_diff($requiredPatchIds, [$failedPatchId]);
        if ($this->isApplicable(array_merge($poolToCompare, [$failedPatchId]))) {
            return '';
        }

        while (count($poolToCompare)) {
            $patchId = array_pop($poolToCompare);
            if ($this->isApplicable(array_merge($poolToCompare, [$failedPatchId]))) {
                return sprintf(
                    'Patch %s is not compatible with required: %s',
                    $failedPatchId,
                    $patchId
                );
            }
        }

        if (!$this->isApplicable([$failedPatchId])) {
            return 'Patch ' . $failedPatchId . ' can\'t be applied to clean Magento instance';
        }

        return '';
    }

    /**
     * Returns true if listed patches with all dependencies can be applied to clean Magento instance.
     *
     * @param string[] $patchIds
     * @return bool
     */
    private function isApplicable(array $patchIds): bool
    {
        $patchItems = $this->optionalPool->getList($patchIds);
        $content = $this->getContent($patchItems);

        return $this->applier->checkApply($content);
    }

    /**
     * Returns aggregated patch content.
     *
     * @param PatchInterface[] $patches
     *
     * @return string
     */
    private function getContent(array $patches): string
    {
        $result = '';
        foreach ($patches as $patch) {
            $result .= $this->filesystem->get($patch->getPath());
        }

        return $result;
    }

    /**
     * Returns ids of required patches.
     *
     * @return string[]
     */
    private function getRequiredIds(): array
    {
        $result = array_map(
            function (PatchInterface $patch) {
                return $patch->getId();
            },
            $this->requiredPool->getList()
        );

        return array_unique($result);
    }

    /**
     * Cleanup Magento instance from patches.
     *
     * @return void
     */
    private function cleanupInstance()
    {
        $this->logger->info('Revert all required patches before conflict analyzing');
        $patchesToRevert = $this->requiredPool->getList();
        foreach (array_reverse($patchesToRevert) as $patch) {
            try {
                $this->applier->revert($patch->getPath(), $patch->getId());
            } catch (ApplierException $exception) {
                // do nothing
            }
        }
    }
}
