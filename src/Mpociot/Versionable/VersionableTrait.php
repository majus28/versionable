<?php

namespace Mpociot\Versionable;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Class VersionableTrait
 * @package Mpociot\Versionable
 */
trait VersionableTrait
{

    /**
     * Retrieve, if exists, the property that define that Version model.
     * If no property defined, use the default Version model.
     *
     * Trait cannot share properties whth their class !
     * http://php.net/manual/en/language.oop5.traits.php
     * @return unknown|string
     */
    protected function getVersionClass()
    {
        if (property_exists(self::class, 'versionClass')) {
            return $this->versionClass;
        }

        return config('versionable.version_model', Version::class);
    }

    /**
     * Private variable to detect if this is an update
     * or an insert
     * @var bool
     */
    private $updating;

    /**
     * Contains all dirty data that is valid for versioning
     *
     * @var array
     */
    private $versionableDirtyData;

    /**
     * Optional reason, why this version was created
     * @var string
     */
    private $reason;

    /**
     * Flag that determines if the model allows versioning at all
     * @var bool
     */
    protected $versioningEnabled = true;

    /**
     * @return $this
     */
    public function enableVersioning()
    {
        $this->versioningEnabled = true;
        return $this;
    }

    /**
     * @return $this
     */
    public function disableVersioning()
    {
        $this->versioningEnabled = false;
        return $this;
    }

    /**
     * Attribute mutator for "reason"
     * Prevent "reason" to become a database attribute of model
     *
     * @param string $value
     */
    public function setReasonAttribute($value)
    {
        $this->reason = $value;
    }

    /**
     * Initialize model events
     */
    public static function bootVersionableTrait()
    {
        static::saving(function ($model) {
            $model->versionablePreSave();
        });

        static::saved(function ($model) {
            $model->versionablePostSave();
        });

    }

    /**
     * Return all versions of the model
     * @return MorphMany
     */
    public function versions()
    {
        return $this->morphMany($this->getVersionClass(), 'versionable');
    }

    /**
     * Returns the latest version available
     * @return Version
     */
    public function currentVersion()
    {
        $class = $this->getVersionClass();
        return $this->versions()->orderBy('is_active', 'Yes')->first();
    }

    /**
     * Returns the previous version
     * @param null $id
     * @return Version
     */
    public function previousVersion($id = null)
    {
        $model = $this->versions();
        if ($id) {
            $model = $model->where('version_id', '<', $id);
        }
        return $model->latest()->limit(1)->first();
    }

    /**
     * Get a model based on the version id
     *
     * @param $version_id
     *
     * @return $this|null
     */
    public function getVersionModel($version_id)
    {
        $version = $this->versions()->where("version_id", "=", $version_id)->first();
        if (!is_null($version)) {
            return $version->getModel();
        }
        return null;
    }

    /**
     * Pre save hook to determine if versioning is enabled and if we're updating
     * the model
     * @return void
     */
    protected function versionablePreSave()
    {
        if ($this->versioningEnabled === true) {
            $this->versionableDirtyData = $this->getDirty();
            $this->updating = $this->exists;
        }
    }

    /**
     * Save a new version.
     * @return void
     */
    protected function versionablePostSave()
    {

        /**
         * We'll save new versions on updating and first creation
         */
        if (
            ($this->versioningEnabled === true && $this->updating && $this->isValidForVersioning()) ||
            ($this->versioningEnabled === true && !$this->updating && !is_null($this->versionableDirtyData) && count($this->versionableDirtyData))
        ) {
            $this->updateVersion();
            // Save a new version
            $class = $this->getVersionClass();
            $version = new $class();
            $key = $this->getKey();
            $versionable = $this->versionable;
            $versions = Version::where('versionable_id', $key)->get();
            foreach ($versions as $ver) {
                $data = $this->toArray();
                $duplicate = [];
                $old = unserialize($ver->model_data);
                foreach ($this->versionable as $item) {
                    $oldData = array_get($old, $item);
                    $newData = array_get($data, $item);
                    if ($oldData == $newData) {
                        $duplicate[] = $oldData == $newData;
                    }
                }
                if (count($versionable) == count($duplicate)) {
                    $ver->is_active = 'Yes';
                    $ver->save();
                    $this->purgeOldVersions();
                    return;
                }
            }
            $version->versionable_id = $this->getKey();
            $version->versionable_type = get_class($this);
            $version->user_id = $this->getAuthUserId();
            $version->is_active = 'Yes';
            $version->model_data = serialize($this->getAttributes());

            if (!empty($this->reason)) {
                $version->reason = $this->reason;
            }

            $version->save();

            $this->purgeOldVersions();
        }
    }

    /**
     * Delete old versions of this model when the reach a specific count.
     *
     * @return void
     */
    private function purgeOldVersions()
    {
        $keep = isset($this->keepOldVersions) ? $this->keepOldVersions : 0;
        $count = $this->versions()->count();

        if ((int)$keep > 0 && $count > $keep) {
            $oldVersions = $this->versions()
                ->latest()
                ->take($count)
                ->skip($keep)
                ->get()
                ->each(function ($version) {
                    $version->delete();
                });
        }
    }

    /**
     * Determine if a new version should be created for this model.
     *
     * @return bool
     */
    private function isValidForVersioning()
    {
        $dontVersionFields = isset($this->dontVersionFields) ? $this->dontVersionFields : [];
        $removeableKeys = array_merge($dontVersionFields, [$this->getUpdatedAtColumn()]);

        if (method_exists($this, 'getDeletedAtColumn')) {
            $removeableKeys[] = $this->getDeletedAtColumn();
        }

        return (count(array_diff_key($this->versionableDirtyData, array_flip($removeableKeys))) > 0);
    }

    /**
     * @return int|null
     */
    protected function getAuthUserId()
    {
        if (Auth::check()) {
            return Auth::id();
        }
        return null;
    }


    public function updateVersion()
    {
        $this->versions()->update(['is_active' => 'No']);
    }
}
