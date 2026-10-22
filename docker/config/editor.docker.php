<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Configurations for Metadata Editor (Docker Environment)
|--------------------------------------------------------------------------
| Note: Both the Metadata Editor (PHP) and the FastAPI service MUST see
| project data files at the same absolute path (`STORAGE_PATH`).
*/

// Storage root folder for editor (default matches FastAPI STORAGE_PATH parent)
$storage_root = getenv('EDITOR_STORAGE_PATH') ?: '/var/www/metadata-editor/editor/datafiles/editor';
$config['editor']['storage_path'] = rtrim($storage_root, '/');

// Path for storing user-defined schemas
$config['editor']['user_schema_path'] = $config['editor']['storage_path'] . '/user-schemas';

// Python FastAPI server URL; URL must end with a trailing slash
$data_api_url = getenv('EDITOR_DATA_API_URL') ?: 'http://fastapi:8000/';
$config['editor']['data_api_url'] = rtrim($data_api_url, '/') . '/';

// Path for core editor form templates (JSON, shipped with the application)
$config['editor']['core_template_path'] = APPPATH . 'editor_templates';
