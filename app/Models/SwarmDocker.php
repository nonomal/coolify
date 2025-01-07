<?php

namespace App\Models;

class SwarmDocker extends BaseModel
{
    protected $guarded = [];

    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }

    public function postgresqls()
    {
        return $this->morphMany(StandalonePostgresql::class, 'destination');
    }

    public function redis()
    {
        return $this->morphMany(StandaloneRedis::class, 'destination');
    }

    public function keydbs()
    {
        return $this->morphMany(StandaloneKeydb::class, 'destination');
    }

    public function dragonflies()
    {
        return $this->morphMany(StandaloneDragonfly::class, 'destination');
    }

    public function clickhouses()
    {
        return $this->morphMany(StandaloneClickhouse::class, 'destination');
    }

    public function mongodbs()
    {
        return $this->morphMany(StandaloneMongodb::class, 'destination');
    }

    public function mysqls()
    {
        return $this->morphMany(StandaloneMysql::class, 'destination');
    }

    public function mariadbs()
    {
        return $this->morphMany(StandaloneMariadb::class, 'destination');
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function services()
    {
        return $this->morphMany(Service::class, 'destination');
    }

    public function databases()
    {
        $postgresqls = $this->postgresqls;
        $redis = $this->redis;
        $mongodbs = $this->mongodbs;
        $mysqls = $this->mysqls;
        $mariadbs = $this->mariadbs;
        $keydbs = $this->keydbs;
        $dragonflies = $this->dragonflies;
        $clickhouses = $this->clickhouses;

        return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs)->concat($keydbs)->concat($dragonflies)->concat($clickhouses);
    }

    public function attachedTo()
    {
        if ($this->applications?->count() > 0) {
            return true;
        }

        return $this->databases()->count() > 0;
    }
}
