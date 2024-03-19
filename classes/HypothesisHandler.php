<?php

namespace APP\plugins\generic\hypothesis\classes;

use APP\facades\Repo;
use APP\core\Application;
use APP\plugins\generic\hypothesis\classes\Annotation;
use APP\plugins\generic\hypothesis\classes\SubmissionAnnotations;

class HypothesisHandler {
    public function getSubmissionsAnnotations($contextId) {
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        $groupsRequests = $this->getSubmissionsGroupsRequests($submissions, $contextId);
        $submissionsAnnotations = [];
        foreach ($groupsRequests as $groupRequest) {
            $groupResponse = $this->getRequestAnnotations($groupRequest);
            if (!is_null($groupResponse) && $groupResponse['total'] > 0) {
                $submissionsAnnotations = array_merge(
                    $submissionsAnnotations,
                    $this->groupSubmissionsAnnotations($groupResponse)
                );
            }
        }

        return $submissionsAnnotations;
    }

    private function getSubmissionsGroupsRequests($submissions, $contextId) {
        $requests = [];
        $requestPrefix = $currentRequest = "https://hypothes.is/api/search?limit=200&group=__world__";
        $maxRequestLength = 4094;

        foreach ($submissions as $submission) {
            $submissionRequestParams = $this->getSubmissionRequestParams($submission, $contextId);

            if(!is_null($submissionRequestParams)) {
                if(strlen($currentRequest.$submissionRequestParams) < $maxRequestLength) {
                    $currentRequest .= $submissionRequestParams;
                }
                else {
                    $requests[] = $currentRequest;
                    $currentRequest = $requestPrefix . $submissionRequestParams; 
                }
            }
        }

        return $requests;
    }

    private function getSubmissionRequestParams($submission, $contextId) {
        $submissionRequestParams = "";
        $publication = $submission->getCurrentPublication();

        if(is_null($publication))
            return null;

        $galleys = $publication->getData('galleys');
        foreach ($galleys as $galley) {
            $galleyDownloadURL = $this->getGalleyDownloadURL($galley, $contextId);
            if(!is_null($galleyDownloadURL))
                $submissionRequestParams .= "&uri={$galleyDownloadURL}";
        }

        return $submissionRequestParams;
    }

    private function getRequestAnnotations($requestURL) {
        $ch = curl_init($requestURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        if(!$output || substr($output, 1, 8) != '"total":') return null;

        return json_decode($output, true);
    }

    private function groupSubmissionsAnnotations($groupResponse) {
        $submissionsAnnotations = [];

        foreach ($groupResponse['rows'] as $annotationResponse) {
            $urlBySlash = explode("/", $annotationResponse['links']['incontext']);
            $submissionId = (int) $urlBySlash[count($urlBySlash) - 3];

            if(!array_key_exists($submissionId, $submissionsAnnotations)) {
                $submissionsAnnotations[$submissionId] = new SubmissionAnnotations($submissionId);
            }

            $annotation = $this->getAnnotation($annotationResponse);
            $submissionsAnnotations[$submissionId]->addAnnotation($annotation);
        }

        return $submissionsAnnotations;
    }

    private function getAnnotation($annotationResponse): Annotation {
        $user = substr($annotationResponse['user'], 5, strlen($annotationResponse['user']) - 17);
        $dateCreated = $annotationResponse['created'];
        $content = $annotationResponse['text'];

        $target = "";
        if(isset($annotationResponse['target'][0]['selector'])) {
            foreach ($annotationResponse['target'][0]['selector'] as $selector) {
                if($selector['type'] == 'TextQuoteSelector') {
                    $target = $selector['exact'];
                    break;
                }
            }
        }

        return new Annotation($user, $dateCreated, $target, $content);
    }

    public function getGalleyDownloadURL($galley, $contextId) {
        $request = Application::get()->getRequest();
        $indexUrl = $request->getIndexUrl();
        $context = Application::getContextDAO()->getById($contextId);
        $contextPath = $context->getPath();

        $submissionFile = $galley->getFile();
        if(is_null($submissionFile))
            return null;

        $submissionId = $submissionFile->getData('submissionId');
        $assocId = $submissionFile->getData('assocId');
        $submissionFileId = $submissionFile->getId();

        return $indexUrl . "/$contextPath/preprint/download/$submissionId/$assocId/$submissionFileId";
    }
}