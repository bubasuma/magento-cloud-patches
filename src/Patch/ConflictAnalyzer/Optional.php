<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CloudPatches\Patch\ConflictAnalyzer;

use Magento\CloudPatches\Filesystem\Filesystem;
use Magento\CloudPatches\Patch\Applier;
use Magento\CloudPatches\Patch\Data\PatchInterface;
use Magento\CloudPatches\Patch\Environment;
use Magento\CloudPatches\Patch\Pool\OptionalPool;
use Magento\CloudPatches\Patch\ConflictAnalyzer\Required as RequiredConflictAnalyzer;

/**
 * Analyzes optional patch conflicts.
 */
class Optional
{
    /**
     * @var Applier
     */
    private $applier;

    /**
     * @var OptionalPool
     */
    private $optionalPool;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var RequiredConflictAnalyzer
     */
    private $requiredConflictAnalyzer;

    /**
     * @param Applier $applier
     * @param OptionalPool $optionalPool
     * @param Environment $environment
     * @param Filesystem $filesystem
     * @param Required $requiredConflictAnalyzer
     */
    public function __construct(
        Applier $applier,
        OptionalPool $optionalPool,
        Environment $environment,
        Filesystem $filesystem,
        RequiredConflictAnalyzer $requiredConflictAnalyzer
    ) {
        $this->applier = $applier;
        $this->optionalPool = $optionalPool;
        $this->environment = $environment;
        $this->filesystem = $filesystem;
        $this->requiredConflictAnalyzer = $requiredConflictAnalyzer;
    }

    /**
     * Returns details about conflict.
     *
     * Identifies which particular patch(es) leads to conflict.
     * Works only on Cloud since we need to have a clean Magento instance before analyzing.
     *
     * @param string $failedPatchId
     * @param array $patchFilter
     * @return string
     */
    public function analyze(string $failedPatchId, array $patchFilter = []): string
    {
        if (!$this->environment->isCloud()) {
            return '';
        }

        $messages[] = $this->requiredConflictAnalyzer->analyze($failedPatchId);
        if (!$this->isApplicable([$failedPatchId])) {
            return 'Patch ' . $failedPatchId . ' can\'t be applied to clean Magento instance';
        }

        $optionalPatchIds = $patchFilter ? $patchFilter : $this->getOptionalIds();
        $ids = $this->getIncompatiblePatches($optionalPatchIds, $failedPatchId);
        if ($ids) {
            $messages[] = sprintf(
                'Patch %s is not compatible with optional: %s',
                $failedPatchId,
                implode(' ', $ids)
            );
        }

        return implode(PHP_EOL, array_filter($messages));
    }

    /**
     * Returns ids of incompatible patches.
     *
     * @param string[] $patchesToCompare
     * @param string $patchId
     * @return array
     */
    private function getIncompatiblePatches(array $patchesToCompare, string $patchId): array
    {
        $result = [];
        $patchesToCompare = array_diff($patchesToCompare, [$patchId]);
        foreach (array_unique($patchesToCompare) as $compareId) {
            if (!$this->isApplicable([$compareId, $patchId])) {
                $result[] = $compareId;
            }
        }

        return $result;
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
     * Returns ids of optional, not deprecated patches.
     *
     * @return string[]
     */
    private function getOptionalIds(): array
    {
        $items = array_filter(
            $this->optionalPool->getList(),
            function ($patch) {
                return !$patch->isDeprecated() && $patch->getType() === PatchInterface::TYPE_OPTIONAL;
            }
        );

        $result = array_map(
            function (PatchInterface $patch) {
                return $patch->getId();
            },
            $items
        );

        return array_unique($result);
    }
}
