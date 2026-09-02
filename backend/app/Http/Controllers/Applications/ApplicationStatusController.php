<?php

namespace App\Http\Controllers\Applications;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\ApplicationService;

class ApplicationStatusController extends Controller
{
    public function deactivate(Application $application, ApplicationService $applications): ApplicationResource
    {
        return $this->changeStatus($application, $applications, ApplicationStatus::Inactive);
    }

    public function activate(Application $application, ApplicationService $applications): ApplicationResource
    {
        return $this->changeStatus($application, $applications, ApplicationStatus::Active);
    }

    private function changeStatus(
        Application $application,
        ApplicationService $applications,
        ApplicationStatus $status,
    ): ApplicationResource {
        $this->authorize('changeStatus', $application);

        return new ApplicationResource($applications->changeStatus($application, $status));
    }
}
