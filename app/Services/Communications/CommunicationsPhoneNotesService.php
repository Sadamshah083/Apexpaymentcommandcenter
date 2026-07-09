<?php

namespace App\Services\Communications;

use App\Models\CommunicationPhoneNote;
use App\Models\User;
use App\Models\Workspace;
use App\Support\UsPhoneNormalizer;
use Illuminate\Support\Collection;

class CommunicationsPhoneNotesService
{
    public function normalizePhoneKey(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $us = UsPhoneNormalizer::normalize($phone);
        if ($us) {
            return $us;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== '' ? $digits : null;
    }

    public function getForPhone(Workspace $workspace, string $phone): ?CommunicationPhoneNote
    {
        $key = $this->normalizePhoneKey($phone);
        if (! $key) {
            return null;
        }

        return CommunicationPhoneNote::query()
            ->where('workspace_id', $workspace->id)
            ->where('normalized_phone', $key)
            ->first();
    }

    /**
     * @param  array<int, string>  $phones
     * @return array<string, string>
     */
    public function mapBodiesForPhones(Workspace $workspace, array $phones): array
    {
        $keys = collect($phones)
            ->map(fn (string $phone) => $this->normalizePhoneKey($phone))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($keys === []) {
            return [];
        }

        return CommunicationPhoneNote::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('normalized_phone', $keys)
            ->get()
            ->mapWithKeys(fn (CommunicationPhoneNote $row) => [$row->normalized_phone => (string) ($row->body ?? '')])
            ->all();
    }

    public function upsertForPhone(Workspace $workspace, User $user, string $phone, ?string $body): CommunicationPhoneNote
    {
        $key = $this->normalizePhoneKey($phone);
        if (! $key) {
            throw new \InvalidArgumentException('Invalid phone number for notes.');
        }

        return CommunicationPhoneNote::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'normalized_phone' => $key,
            ],
            [
                'body' => $body,
                'updated_by_user_id' => $user->id,
            ],
        );
    }

    public function bodyForPhone(Workspace $workspace, string $phone): string
    {
        return (string) ($this->getForPhone($workspace, $phone)?->body ?? '');
    }

    /**
     * @param  Collection<int, string>  $phones
     * @return array<string, string>
     */
    public function bodiesByNormalizedKeys(Workspace $workspace, Collection $phones): array
    {
        return $this->mapBodiesForPhones($workspace, $phones->all());
    }
}
