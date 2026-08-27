<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Auth\AuthorizationDenied;
use app\application\Radio\RadioInvalid;
use app\application\Radio\RadioNotFound;
use app\application\Radio\RadioService;
use app\application\Radio\RadioValidator;

/**
 * Adapts Velin's radio catalog to Subsonic v1.16.1 internet-radio operations.
 *
 * Reads require `play`; mutations require `manage_system`. The protocol has no optimistic version,
 * so update/delete deliberately use RadioService's last-command-wins path. External artwork is emitted
 * as `imageUrl`; no fake coverArt ID is returned because `getCoverArt` cannot safely resolve it. This
 * adapter never fetches, probes, proxies, or resolves any submitted URL.
 */
final readonly class SubsonicRadioService
{
    public function __construct(
        private RadioService $radios = new RadioService(),
        private RadioValidator $validator = new RadioValidator(),
    ) {
    }

    /** Returns all enabled stations visible to a listener in stable name order. */
    public function get(array $actor): array
    {
        $this->requireCapability($actor, 'play');
        $stations = [];
        for ($offset = 0; $offset <= 10_000; $offset += 100) {
            $page = $this->radios->page($actor, 100, $offset);
            foreach ($page['stations'] as $station) {
                if (($station['enabled'] ?? false) === true) {
                    $stations[] = $this->map($station);
                }
            }
            if ($page['stations'] === [] || count($stations) >= $page['total']) {
                break;
            }
        }

        return ['internetRadioStations' => ['internetRadioStation' => $stations]];
    }

    /** Creates one station from required protocol fields using the same public-URL validation as Web. */
    public function create(array $actor, array $parameters, string $requestId): array
    {
        $this->requireCapability($actor, 'manage_system');
        try {
            $this->radios->create($actor, $this->validator->command($parameters, legacy: true, creating: true), $requestId);
        } catch (RadioInvalid $exception) {
            throw new SubsonicRequestInvalid('Internet-radio fields are invalid.', previous: $exception);
        } catch (AuthorizationDenied $exception) {
            throw new SubsonicAuthorizationDenied('Internet-radio management is not authorized.', previous: $exception);
        }

        return [];
    }

    /** Replaces one canonical station through the protocol's intentional last-command-wins contract. */
    public function update(array $actor, array $parameters, string $requestId): array
    {
        $this->requireCapability($actor, 'manage_system');
        try {
            $this->radios->update(
                $actor,
                $this->validator->id($parameters['id'] ?? null),
                $this->validator->command($parameters, legacy: true),
                $requestId,
            );
        } catch (RadioInvalid $exception) {
            throw new SubsonicRequestInvalid('Internet-radio fields are invalid.', previous: $exception);
        } catch (RadioNotFound $exception) {
            throw new SubsonicEntityNotFound('Internet-radio station was not found.', previous: $exception);
        } catch (AuthorizationDenied $exception) {
            throw new SubsonicAuthorizationDenied('Internet-radio management is not authorized.', previous: $exception);
        }

        return [];
    }

    /** Idempotently deletes a canonical station ID and cascades only local favorite relations. */
    public function delete(array $actor, mixed $stationId, string $requestId): array
    {
        $this->requireCapability($actor, 'manage_system');
        try {
            $this->radios->delete($actor, $this->validator->id($stationId), null, $requestId);
        } catch (RadioInvalid $exception) {
            throw new SubsonicRequestInvalid('Internet-radio station ID is invalid.', previous: $exception);
        } catch (AuthorizationDenied $exception) {
            throw new SubsonicAuthorizationDenied('Internet-radio management is not authorized.', previous: $exception);
        }

        return [];
    }

    /** @return array<string, mixed> Maps only protocol-defined values and the external image extension. */
    private function map(array $station): array
    {
        $result = [
            'id' => (string) $station['id'],
            'name' => (string) $station['name'],
            'streamUrl' => (string) $station['streamUrl'],
        ];
        if (is_string($station['homepageUrl'] ?? null) && $station['homepageUrl'] !== '') {
            $result['homePageUrl'] = $station['homepageUrl'];
        }
        if (is_string($station['artworkUrl'] ?? null) && $station['artworkUrl'] !== '') {
            $result['imageUrl'] = $station['artworkUrl'];
        }

        return $result;
    }

    /** Enforces protocol permissions before any catalog query or mutation is attempted. */
    private function requireCapability(array $actor, string $capability): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array($capability, $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Internet-radio operation is not authorized.');
        }
    }
}
