<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');

class UpdateAnnotationsCache extends ScheduledTask {

	public function executeActions() {
        $contextIds = Services::get('context')->getIds([
			'isEnabled' => true,
		]);
        
        foreach ($contextIds as $contextId) {
            $cacheManager = CacheManager::getManager();
            $cache = $cacheManager->getFileCache(
                $contextId,
                'submissions_annotations',
                [$this, 'cacheDismiss']
            );

            $cache->flush();
            $hypothesisHandler = new HypothesisHandler();
			$cache->setEntireCache($hypothesisHandler->getSubmissionsAnnotations($contextId));
        }

		return true;
	}

    function cacheDismiss() {
		return null;
	}
}

