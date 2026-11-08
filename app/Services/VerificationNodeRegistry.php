<?php

namespace App\Services;

use App\Models\VerificationNode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VerificationNodeRegistry
{
    public function __construct(private readonly VerificationNodeIdentity $identity) {}

    /**
     * @return array{node_id: string, instance_id: string}
     */
    public function registerCurrentNode(): array
    {
        $nodeId = $this->identity->nodeId();
        $installationId = $this->identity->installationId();
        $bootId = $this->identity->bootId();
        $instanceId = $this->identity->instanceId();

        $now = now();
        DB::table('verification_nodes')->insertOrIgnore([
            'node_id' => $nodeId,
            'installation_id' => $installationId,
            'boot_id' => $bootId,
            'instance_id' => $instanceId,
            'registered_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::transaction(function () use ($nodeId, $installationId, $bootId, $instanceId) {
            $registered = VerificationNode::whereKey($nodeId)->lockForUpdate()->firstOrFail();

            if (! hash_equals($registered->installation_id, $installationId)) {
                throw new RuntimeException('Duplicate active provider verification node identity detected.');
            }

            // The same durable installation may legitimately reboot. A
            // distinct installation can never replace this row implicitly.
            $registered->forceFill([
                'boot_id' => $bootId,
                'instance_id' => $instanceId,
                'last_seen_at' => now(),
            ])->save();

            return ['node_id' => $nodeId, 'instance_id' => $instanceId];
        });
    }
}
