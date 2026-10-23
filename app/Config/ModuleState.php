<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Which optional modules an administrator has switched off (Admin > Modules). Read with
 * setting('ModuleState.disabled'); this property is only the default (nothing disabled).
 */
class ModuleState extends BaseConfig
{
    /** @var list<string> module keys */
    public array $disabled = [];
}
