<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Itheorie\CreatePurchaseRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetCourseRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetPurchaseRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetStudentDetailedRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetStudentRequest;
use Emeq\HubSdk\Http\Request\Itheorie\ListCoursesRequest;

class Itheorie extends Resource
{
    /** @return array<string, mixed> */
    public function courses(?int $page = null, ?int $limit = null): array
    {
        $response = $this->connector->send(new ListCoursesRequest($page, $limit));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function course(string $courseId): array
    {
        $response = $this->connector->send(new GetCourseRequest($courseId));

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $purchase
     * @return array<string, mixed>
     */
    public function createPurchase(array $purchase, string $idempotencyKey): array
    {
        $response = $this->connector->send(new CreatePurchaseRequest($purchase, $idempotencyKey));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function purchase(string $purchaseId): array
    {
        $response = $this->connector->send(new GetPurchaseRequest($purchaseId));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function student(string $accessCode): array
    {
        $response = $this->connector->send(new GetStudentRequest($accessCode));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function studentDetailed(string $accessCode): array
    {
        $response = $this->connector->send(new GetStudentDetailedRequest($accessCode));

        return $this->json($response->json());
    }
}
