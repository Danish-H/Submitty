<?php

namespace app\controllers;

use app\controllers\AbstractController;
use app\libraries\Core;
use app\libraries\DateUtils;
use app\libraries\response\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use app\libraries\response\JsonResponse;

class ErrorController extends AbstractController {


	/**
     * @Route("/courses/{_semester}/{_course}/no_access")
     */
    public function noAccess() {
        $this->core->getOutput()->renderOutput(
            'Error',
            'noAccessCourse',
            $this->canRejoinCourse(),
            $this->core->buildCourseUrl(["rejoin_course"])
        );
    }

    /** 
     * Returns if the user is allowed to self-readd to a course after being dropped.
     * @param bool True if can readd, false otherwise.
     */
    private function canRejoinCourse() {
        $user = $this->core->getUser();

        // If manually removed from course, this was probably intentional removal.
        if ($user->isManualRegistration()) {
            return false;
        }

        $user_id = $user->getId();
        $most_recent_access = $this->core->getQueries()->getMostRecentGradeableAccessForUser($user_id);
        // If removed from course within last 3 days, can readd self.
        if (DateUtils::calculateDayDiff($most_recent_access) <= 3) {
            return true;
        }

        $term_start_date = $this->core->getQueries()->getCurrentTermStartDate();
        // If never accessed course but is within first two weeks of term, can readd self.
        if (DateUtils::calculateDayDiff($most_recent_access, $term_start_date) <= 14) {
            return true;
        }

        return false;
    }

    private function console_log($output, $with_script_tags = true) {
        $js_code = 'console.log(' . json_encode($output, JSON_HEX_TAG) . ');';
        if ($with_script_tags) {
            $js_code = '<script>' . $js_code . '</script>';
        }
        echo $js_code;
    }

    /**
     * @Route("/courses/{_semester}/{_course}/rejoin_course", methods={"POST"})
     */
    public function rejoinCourse() {
        if (!$this->canRejoinCourse())
            return;

        $user_id = $this->core->getUser()->getId();
        $this->core->getQueries()->removeUserFromNullSection($user_id);
        return JsonResponse::getSuccessResponse([
            "message" => "YESSSS"
        ]);
    }

}
