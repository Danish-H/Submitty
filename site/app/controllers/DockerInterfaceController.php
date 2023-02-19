<?php

namespace app\controllers;

use app\models\User;
use app\libraries\FileUtils;
use app\libraries\response\MultiResponse;
use app\libraries\response\WebResponse;
use app\libraries\response\JsonResponse;
use app\views\ErrorView;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class DockerInterfaceController
 *
 * Works with Docker to provide a user interface
 *
 */
class DockerInterfaceController extends AbstractController {
    /**
     * @Route("/admin/docker", methods={"GET"})
     * @Route("/api/docker", methods={"GET"})
     * @return MultiResponse
     */
    public function showDockerInterface(): MultiResponse {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        $json = [];

        $json['autograding_containers'] = FileUtils::readJsonFile(
            FileUtils::joinPaths(
                $this->core->getConfig()->getSubmittyInstallPath(),
                "config",
                "autograding_containers.json"
            )
        );

        $json['autograding_workers'] = FileUtils::readJsonFile(
            FileUtils::joinPaths(
                $this->core->getConfig()->getSubmittyInstallPath(),
                "config",
                "autograding_workers.json"
            )
        );

        return new MultiResponse(
            JsonResponse::getSuccessResponse($json),
            new WebResponse(
                ['admin', 'Docker'],
                'displayDockerPage',
                $json
            )
        );
    }
    /**
     * @Route("/admin/add_image", methods={"POST"})
     * @Route("/api/admin/add_image", methods={"GET"})
     * @return JsonResponse | MultiResponse
     */
    public function addImage() {
        $user = $this->core->getUser();
        $user_id = $this->core->getUser()->getId();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse(ErrorView::class, "errorPage", "You don't have access to this page.")
            );
        }

        if (!isset($_POST['image'])) {
            return JsonResponse::getErrorResponse("Image not set");
        }
        if (!isset($_POST['capability'])) {
            return JsonResponse::getErrorResponse("Capability not set");
        }

        // check for proper format
        $match = preg_match('/^[a-z0-9]+[a-z0-9._(__)-]*[a-z0-9]+\/[a-z0-9]+[a-z0-9._(__)-]*[a-z0-9]+:[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/', $_POST['image']);

        if ($match === false) {
            return JsonResponse::getErrorResponse("An error has occurred when verifying image name");
        }

        if ($match === 0) {
            return JsonResponse::getErrorResponse("Improper docker image name");
        }

        $image_arr = explode(":", $_POST['image']);
        // ping the dockerhub API to check if docker exists
        $url = "https://registry.hub.docker.com/v2/repositories/" . $image_arr[0] . "/tags";
        $tag = $image_arr[1];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $return_str = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $code_success = !$http_code == 200;
        if (curl_errno($ch) || $http_code !== 200) {
            return JsonResponse::getErrorResponse($image_arr[0] . ' not found on DockerHub');
        }
        $return_json = (array) json_decode($return_str);
        if (!isset($return_json['results'])) {
            return JsonResponse::getFailResponse($_POST['image'] . ' not found on DockerHub');
        }
        $found = false;
        foreach ($return_json['results'] as $result) {
            if ($result->name === $tag) {
                $found = true;
                break;
            }
        }

        if ($found) {
            $json = FileUtils::readJsonFile(
                FileUtils::joinPaths(
                    $this->core->getConfig()->getSubmittyInstallPath(),
                    "config",
                    "autograding_containers.json"
                )
            );

            if (!array_key_exists($_POST['capability'], $json)) {
                $json[$_POST['capability']] = [];
            }

            if (!in_array($_POST['image'], $json[$_POST['capability']])) {
                $json[$_POST['capability']][] = $_POST['image'];
                $this->core->getQueries()->setDockerImageOwner($_POST['image'], $user_id);
            }
            else {
                return JsonResponse::getFailResponse($_POST['image'] . ' already exists in capability ' . $_POST['capability']);
            }
            FileUtils::writeJsonFile(
                FileUtils::joinPaths(
                    $this->core->getConfig()->getSubmittyInstallPath(),
                    "config",
                    "autograding_containers.json"
                ),
                $json
            );

            if (!$this->updateDocker()) {
                return JsonResponse::getFailResponse("Could not update docker images, please try again later.");
            }
            return JsonResponse::getSuccessResponse($_POST['image'] . ' found on DockerHub and queued to be added!');
        }
        else {
            return JsonResponse::getFailResponse($_POST['image'] . ' not found on DockerHub');
        }
    }

    /**
     * @Route("/admin/remove_image", methods={"POST"})
     * @return JsonResponse
     */
    public function removeImage() {
        $user = $this->core->getUser();
        $user_id = $this->core->getUser()->getId();
        $jsonFilePath = FileUtils::joinPaths(
            $this->core->getConfig()->getSubmittyInstallPath(),
            "config",
            "autograding_containers.json"
        );
        $json = json_decode(file_get_contents($jsonFilePath), true);
        $key = array_search($_POST['image'], $json[$_POST['capability']]);
        $Verify = $this->core->getQueries()->removeDockerImageOwner($_POST['image'], $user_id);
        if ($key !== false && $Verify != false) {
            unset($json[$_POST['capability']][$key]);
            $json[$_POST['capability']] = array_values($json[$_POST['capability']]);
            file_put_contents($jsonFilePath, json_encode($json, JSON_PRETTY_PRINT));
            return JsonResponse::getSuccessResponse($_POST['image'] . ' removed from docker images!');
        }
        else if ($key !== false && ($user->getAccessLevel() === User::LEVEL_SUPERUSER)) {
            unset($json[$_POST['capability']][$key]);
            $json[$_POST['capability']] = array_values($json[$_POST['capability']]);
            file_put_contents($jsonFilePath, json_encode($json, JSON_PRETTY_PRINT));
            return JsonResponse::getSuccessResponse($_POST['image'] . ' removed from docker images!');
        }
        else {
            return JsonResponse::getFailResponse('This image is owned/managed by another instructur/superuser.');
        }
    }


    /**
     * @Route("/admin/update_docker", methods={"GET"})
     * @return JsonResponse
     */
    public function updateDockerCall() {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return JsonResponse::getFailResponse("You don't have access to this endpoint.");
        }
        if (!$this->updateDocker()) {
            return JsonResponse::getErrorResponse("Failed to write to file");
        }
        return JsonResponse::getSuccessResponse("Successfully queued the system to update docker, please refresh the page in a bit.");
    }

    /**
     * @return bool
     */
    private function updateDocker() {
        $now = $this->core->getDateTimeNow()->format('Ymd');
        $docker_job_file = FileUtils::joinPaths($this->core->getConfig()->getSubmittyPath(), "daemon_job_queue/docker" . $now . ".json");
        $docker_data = [
            "job" => "UpdateDockerImages"
        ];

        if (
            (!is_writable($docker_job_file) && file_exists($docker_job_file))
            || file_put_contents($docker_job_file, json_encode($docker_data, JSON_PRETTY_PRINT)) === false
        ) {
            return false;
        }
        return true;
    }
}
