<?php

namespace ErnestDefoe\TimeCapsule\Gdpr;

use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Vault;
use Flarum\Gdpr\Data\Type;

/**
 * The member's letters in a data export: opened ones in full, sealed ones as
 * their card (the export doesn't break a seal the writer chose). Gone, photos
 * too, on erasure. Registered only when flarum/gdpr is enabled.
 */
class TimeCapsuleData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-time-capsule.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-time-capsule.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $vault = resolve(Vault::class);
        $export = [];

        foreach (Capsule::query()->where('user_id', $this->user->id)->get() as $capsule) {
            $row = $capsule->only(['envelope', 'opens_at', 'sealed_at', 'opened_at', 'visibility', 'words', 'photo_count']);
            if ($capsule->isOpen() || $capsule->author_can_read) {
                $row['letter'] = $vault->open($capsule->sealed_body, $capsule->context());
            }
            $export[] = ["time-capsule/letter-{$capsule->id}.json" => $this->encodeForExport($row)];
        }

        return $export;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        resolve(Photos::class)->deleteCapsules(Capsule::query()->where('user_id', $this->user->id)->pluck('id'));
    }
}
