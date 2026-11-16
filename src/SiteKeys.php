<?php

namespace porro\craft;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\services\Security;
use Porro\Core\SiteKey;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use yii\base\Component;

/**
 * Where the site key comes from. PORRO_KEY in the environment wins, so a
 * site connected with `php craft porro/connect` or its host's settings is
 * unchanged. Otherwise it's the key made by "Connect to Porro" in the
 * control panel, kept in the site's own database, encrypted with Craft's
 * security key.
 *
 * Everything that needs the key asks here (through Plugin::key()), never
 * the environment itself.
 */
class SiteKeys extends Component
{
    public const TABLE = '{{%porro_settings}}';

    public const ENV = 'env';

    public const STORED = 'stored';

    private const ROW = 'site_key';

    private const PROBE = 'write_check';

    private const TURNED_AWAY = 'turned_away';

    /**
     * Who the stored key is connected to. Never the key itself: that is
     * read and decrypted each time it's needed, and kept nowhere.
     *
     * @var array{workspace: string|null, host: string|null, connected_at: string|null}|false|null
     */
    private array|false|null $stored = null;

    /**
     * The site key token, or null when the site isn't connected.
     */
    public function token(): ?string
    {
        return $this->fromEnv() ?? ($this->read()['token'] ?? null);
    }

    /**
     * "env" or "stored", or null when there's no key.
     */
    public function source(): ?string
    {
        return match (true) {
            $this->fromEnv() !== null => self::ENV,
            $this->stored() !== null => self::STORED,
            default => null,
        };
    }

    public function fromEnv(): ?string
    {
        $key = App::env('PORRO_KEY');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Who the key made in the control panel is connected to, and since
     * when. Safe to show: the key is not in it (token() is the only way
     * to that). Null when there isn't one, the table isn't there yet, or
     * Craft's security key has changed since and it can't be read.
     *
     * @return array{workspace: string|null, host: string|null, connected_at: string|null}|null
     */
    public function stored(): ?array
    {
        if ($this->stored === null) {
            $this->read();
        }

        return $this->stored ?: null;
    }

    /**
     * The stored row, decrypted. Only for token(): nothing keeps what
     * this returns.
     *
     * @return array{token: string}|null
     */
    private function read(): ?array
    {
        try {
            $value = (new Query)->select(['value'])->from(self::TABLE)->where(['key' => self::ROW])->scalar();
            $plain = is_string($value) && $value !== '' ? self::security()->decryptByKey((string) base64_decode($value, true)) : false;
            $data = is_string($plain) ? json_decode($plain, true) : null;
        } catch (Throwable) {
            // No table yet, or a row this security key can't read.
            $data = null;
        }

        if (! is_array($data) || ! is_string($data['token'] ?? null) || $data['token'] === '') {
            $this->stored = false;

            return null;
        }

        $this->stored = [
            'workspace' => is_string($data['workspace'] ?? null) ? $data['workspace'] : null,
            'host' => is_string($data['host'] ?? null) ? $data['host'] : null,
            'connected_at' => is_string($data['connected_at'] ?? null) ? $data['connected_at'] : null,
        ];

        return ['token' => $data['token']];
    }

    /**
     * Keep a key made in the control panel. Throws when the table is
     * missing, so a connection is never reported as done when it isn't.
     */
    public function store(#[SensitiveParameter] SiteKey $key, ?string $workspace = null, ?string $host = null): void
    {
        $value = self::seal((string) json_encode([
            'token' => $key->token(),
            'workspace' => $workspace,
            'host' => $host,
            'connected_at' => gmdate('c'),
        ]));

        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, ['key' => self::ROW, 'value' => $value])->execute();

        $this->stored = null;

        // Connected again: what happened to the last key is history.
        try {
            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['key' => self::TURNED_AWAY])->execute();
        } catch (Throwable) {
            // The key is kept; the note can wait.
        }
    }

    /**
     * Porro turned the stored key away: the site was removed there, or
     * given a new key. The key is finished, so it's deleted, and who it
     * was for and when is kept so the control panel can say what
     * happened. Does nothing for a key from the environment.
     */
    public function turnAway(): void
    {
        $stored = $this->stored();

        if ($stored === null || $this->fromEnv() !== null) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
                'key' => self::TURNED_AWAY,
                'value' => (string) json_encode(['workspace' => $stored['workspace'], 'at' => gmdate('c')]),
            ])->execute();
        } catch (Throwable) {
            // The note is a nicety; the key still goes.
        }

        $this->forget();
    }

    /**
     * Set when Porro turned the stored key away, until the site connects
     * again. Nothing secret in it, so it's kept as plain JSON.
     *
     * @return array{workspace: string|null, at: string|null}|null
     */
    public function turnedAway(): ?array
    {
        try {
            $value = (new Query)->select(['value'])->from(self::TABLE)->where(['key' => self::TURNED_AWAY])->scalar();
            $data = is_string($value) ? json_decode($value, true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? [
            'workspace' => is_string($data['workspace'] ?? null) ? $data['workspace'] : null,
            'at' => is_string($data['at'] ?? null) ? $data['at'] : null,
        ] : null;
    }

    /**
     * Encrypt something that holds a key, as text a database column can
     * keep. If encrypting fails, the exception carries the plain text in
     * its trace (it's an argument to every call on the way), so it's
     * swapped for one that doesn't.
     */
    public static function seal(#[SensitiveParameter] string $plain): string
    {
        try {
            return base64_encode(self::security()->encryptByKey($plain));
        } catch (Throwable) {
            throw new RuntimeException('Couldn’t encrypt the Porro site key. Check Craft’s security key.');
        }
    }

    /**
     * What seal() made, readable again, or null when it can't be read.
     */
    public static function open(string $sealed): ?string
    {
        try {
            $plain = self::security()->decryptByKey((string) base64_decode($sealed, true));
        } catch (Throwable) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }

    /**
     * Craft's own encryption, keyed with the site's security key.
     */
    private static function security(): Security
    {
        /** @var Security */
        return Craft::$app->getSecurity();
    }

    /**
     * Delete the key made in the control panel. PORRO_KEY in the
     * environment is not this code's to change.
     */
    public function forget(): void
    {
        try {
            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['key' => self::ROW])->execute();
        } catch (Throwable) {
            // No table, nothing kept.
        }

        $this->stored = null;
    }

    /**
     * Whether a key could be kept right now: the table is there and can
     * be written to. Checked with a real write, just before asking Porro
     * to make the key the site's.
     */
    public function ready(): bool
    {
        try {
            $db = Craft::$app->getDb();
            $db->createCommand()->upsert(self::TABLE, ['key' => self::PROBE, 'value' => ''])->execute();
            $db->createCommand()->delete(self::TABLE, ['key' => self::PROBE])->execute();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
