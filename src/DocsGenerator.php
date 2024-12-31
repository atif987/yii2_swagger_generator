<?php

namespace mehran\swaggergenerator;

use Yii;
use ReflectionClass;
use yii\base\InvalidConfigException;
use yii\base\InvalidArgumentException;

class DocsGenerator
{
    private $model;
    private $modelClass;
    private $tableName;
    public $modelName;
    private $columns;

    /**
     * Constructor
     * @param string $modelClass The fully qualified model class name
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    public function __construct($modelClass)
    {
        // Validate if the class exists
        if (!class_exists($modelClass)) {
            throw new InvalidArgumentException("The model class '{$modelClass}' does not exist.");
        }
        
        $this->modelClass = $modelClass;
        
        // Validate if the class has the 'tableName' method
        if (!method_exists($modelClass, 'tableName')) {
            throw new InvalidArgumentException("The model class '{$modelClass}' must have a 'tableName' method.");
        }
        
        $this->tableName = $modelClass::tableName();
        $this->modelName = (new ReflectionClass($modelClass))->getShortName();
        
        // Get database connection
        $db = Yii::$app->get('db');
        if (!$db) {
            throw new InvalidConfigException('Database connection is not configured in Yii::$app->db');
        }
        
        // Get table schema
        $tableSchema = $db->getTableSchema($this->tableName);
        if (!$tableSchema) {
            throw new InvalidConfigException("Table '{$this->tableName}' does not exist in the database.");
        }
        
        $this->columns = $tableSchema->columns;
        
        if (empty($this->columns)) {
            throw new InvalidConfigException("The table '{$this->tableName}' does not have any columns defined.");
        }
    }

    /**
     * Generate complete CRUD Swagger documentation
     * @return string
     */
    public function generateCrudDocs()
    {
        $docs = "/**\n";
        $docs .= $this->generateSchemaComponent();
        $docs .= " */\n";
        $docs .= $this->generateCreateEndpoint();
        $docs .= $this->generateUpdateEndpoint();
        $docs .= $this->generateViewEndpoint();
        $docs .= $this->generateListEndpoint();
        $docs .= $this->generateDeleteEndpoint();
        return $docs;
    }

    /**
     * Generate Schema Component
     * @return string
     */
    private function generateSchemaComponent()
    {
        $docs = " * @OA\Schema(\n";
        $docs .= " *     schema=\"{$this->modelName}\",\n";
        $docs .= " *     title=\"{$this->modelName} Model\",\n";
        $docs .= " *     @OA\Property(property=\"id\", type=\"integer\", description=\"Unique identifier\"),\n";
        foreach ($this->columns as $column) {
            if ($column->name === 'id') continue;

            $type = $this->getSwaggerType($column);
            $required = $column->allowNull ? "" : ", required=true";
            $description = $this->getColumnDescription($column);
            
            $docs .= " *     @OA\Property(\n";
            $docs .= " *         property=\"{$column->name}\",\n";
            $docs .= " *         type=\"{$type}\"{$required},\n";
            $docs .= " *         description=\"{$description}\"\n";
            $docs .= " *     ),\n";
        }

        $docs .= " * )\n";
        return $docs;
    }

    /**
     * Generate Create Endpoint Documentation
     * @return string
     */
    private function generateCreateEndpoint()
    {
                $endpoint = "/**\n" .
                " * @OA\Post(\n" .
                " *     path=\"/{$this->tableName}\",\n" .
                " *     summary=\"Create new {$this->modelName}\",\n" .
                " *     tags={\"{$this->modelName}\"},\n" .
                " *     security={{\"bearerAuth\": {}}},\n";

                if ($this->hasFileUploadColumns()) {
                   $endpoint .= " *     @OA\RequestBody(\n" .
                                " *         required=true,\n" .
                                " *         @OA\MediaType(\n" .
                                " *             mediaType=\"multipart/form-data\",\n" .
                                " *             @OA\Schema(ref=\"#/components/schemas/{$this->modelName}\")\n" .
                                " *         )\n" .
                                " *     ),\n";
                } else {
                    $endpoint .= " *     @OA\RequestBody(\n" .
                                 " *         required=true,\n" .
                                 " *         @OA\JsonContent(ref=\"#/components/schemas/{$this->modelName}\")\n" .
                                 " *     ),\n";
                }

                $endpoint .= " *     @OA\Response(\n" .
                             " *         response=201,\n" .
                             " *         description=\"{$this->modelName} created successfully\",\n" .
                             " *         @OA\JsonContent(ref=\"#/components/schemas/{$this->modelName}\")\n" .
                             " *     ),\n" .
                             " *     @OA\Response(\n" .
                             " *         response=400,\n" .
                             " *         description=\"Invalid input\"\n" .
                             " *     )\n" .
                             " * )\n */";

                return $endpoint;
    }

    /**
     * Generate Update Endpoint Documentation
     * @return string
     */
    private function generateUpdateEndpoint()
    {
        // Start the endpoint documentation
        $endpoint = "/**\n" .
                " * @OA\Put(\n" .
                " *     path=\"/{$this->tableName}/{id}\",\n" .
                " *     summary=\"Update {$this->modelName}\",\n" .
                " *     tags={\"{$this->modelName}\"},\n" .
                " *     security={{\"bearerAuth\":{}}},\n";

        // Add path parameter for ID
        $endpoint .= " *     @OA\Parameter(\n" .
                    " *         name=\"id\",\n" .
                    " *         in=\"path\",\n" .
                    " *         required=true,\n" .
                    " *         description=\"ID of {$this->modelName} to update\",\n" .
                    " *         @OA\Schema(type=\"integer\")\n" .
                    " *     ),\n";

        // Add request body based on whether there are file uploads
        if ($this->hasFileUploadColumns()) {
            $endpoint .= " *     @OA\RequestBody(\n" .
                        " *         required=true,\n" .
                        " *         @OA\MediaType(\n" .
                        " *             mediaType=\"multipart/form-data\",\n" .
                        " *             @OA\Schema(\n";

            // Add required fields
            $requiredFields = [];
            foreach ($this->columns as $column) {
                if ($column->name !== 'id' && !$column->allowNull) {
                    $requiredFields[] = "\"{$column->name}\"";
                }
            }
            if (!empty($requiredFields)) {
                $endpoint .= " *                 required={" . implode(", ", $requiredFields) . "},\n";
            }

            // Add properties
            foreach ($this->columns as $column) {
                if ($column->name === 'id') continue;

                $type = $this->getSwaggerType($column);
                $description = $this->getColumnDescription($column);
                
                $endpoint .= " *                 @OA\Property(\n" .
                            " *                     property=\"{$column->name}\",\n" .
                            " *                     type=\"{$type}\",\n" .
                            " *                     description=\"{$description}\"\n" .
                            " *                 ),\n";
            }

            $endpoint .= " *             )\n" .
                        " *         )\n" .
                        " *     ),\n";
        } else {
            $endpoint .= " *     @OA\RequestBody(\n" .
                        " *         required=true,\n" .
                        " *         @OA\JsonContent(\n";

            // Add required fields
            $requiredFields = [];
            foreach ($this->columns as $column) {
                if ($column->name !== 'id' && !$column->allowNull) {
                    $requiredFields[] = "\"{$column->name}\"";
                }
            }
            if (!empty($requiredFields)) {
                $endpoint .= " *             required={" . implode(", ", $requiredFields) . "},\n";
            }

            // Add properties
            foreach ($this->columns as $column) {
                if ($column->name === 'id') continue;

                $type = $this->getSwaggerType($column);
                $description = $this->getColumnDescription($column);
                
                $endpoint .= " *             @OA\Property(\n" .
                            " *                 property=\"{$column->name}\",\n" .
                            " *                 type=\"{$type}\",\n" .
                            " *                 description=\"{$description}\"\n" .
                            " *             ),\n";
            }

            $endpoint .= " *         )\n" .
                        " *     ),\n";
        }

        // Add responses
        $endpoint .= " *     @OA\Response(\n" .
                    " *         response=200,\n" .
                    " *         description=\"{$this->modelName} updated successfully\",\n" .
                    " *         @OA\JsonContent(ref=\"#/components/schemas/{$this->modelName}\")\n" .
                    " *     ),\n" .
                    " *     @OA\Response(\n" .
                    " *         response=400,\n" .
                    " *         description=\"Invalid input\"\n" .
                    " *     ),\n" .
                    " *     @OA\Response(\n" .
                    " *         response=404,\n" .
                    " *         description=\"{$this->modelName} not found\"\n" .
                    " *     )\n" .
                    " * )\n */";

        return $endpoint;
    }

    /**
     * Generate View Endpoint Documentation
     * @return string
     */
    private function generateViewEndpoint()
    {
        $endpoint = "/** * @OA\Get(\n" .
               " *     path=\"/{$this->tableName}/{id}\",\n" .
               " *     summary=\"View {$this->modelName} details\",\n" .
               " *     tags={\"{$this->modelName}\"},\n" .
               " *     security={{\"bearerAuth\":{}}},\n" .
               " *     @OA\Parameter(\n" .
               " *         name=\"id\",\n" .
               " *         in=\"path\",\n" .
               " *         required=true,\n" .
               " *         @OA\Schema(type=\"integer\")\n" .
               " *     ),\n" .
               " *     @OA\Response(\n" .
               " *         response=200,\n" .
               " *         description=\"{$this->modelName} details\",\n" .
               " *         @OA\JsonContent(ref=\"#/components/schemas/{$this->modelName}\")\n" .
               " *     ),\n" .
               " *     @OA\Response(response=404, description=\"{$this->modelName} not found\")\n" .
               " * )\n*/";
        return $endpoint;
    }

    /**
     * Generate List Endpoint Documentation
     * @return string
     */
    private function generateListEndpoint()
    {
        $endpoint = "/** @OA\Get(\n" .
               " *     path=\"/{$this->tableName}\",\n" .
               " *     summary=\"List all {$this->modelName}s\",\n" .
               " *     tags={\"{$this->modelName}\"},\n" .
               " *     security={{\"bearerAuth\":{}}},\n" .
               " *     @OA\Parameter(\n" .
               " *         name=\"page\",\n" .
               " *         in=\"query\",\n" .
               " *         @OA\Schema(type=\"integer\", default=1)\n" .
               " *     ),\n" .
               " *     @OA\Parameter(\n" .
               " *         name=\"per_page\",\n" .
               " *         in=\"query\",\n" .
               " *         @OA\Schema(type=\"integer\", default=10)\n" .
               " *     ),\n" .
               " *     @OA\Response(\n" .
               " *         response=200,\n" .
               " *         description=\"List of {$this->modelName}s\",\n" .
               " *         @OA\JsonContent(\n" .
               " *             type=\"object\",\n" .
               " *             @OA\Property(property=\"items\", type=\"array\", @OA\Items(ref=\"#/components/schemas/{$this->modelName}\")),\n" .
               " *             @OA\Property(property=\"_meta\", type=\"object\",\n" .
               " *                 @OA\Property(property=\"totalCount\", type=\"integer\"),\n" .
               " *                 @OA\Property(property=\"pageCount\", type=\"integer\"),\n" .
               " *                 @OA\Property(property=\"currentPage\", type=\"integer\"),\n" .
               " *                 @OA\Property(property=\"perPage\", type=\"integer\")\n" .
               " *             )\n" .
               " *         )\n" .
               " *     )\n" .
               " * )\n*/";
        return $endpoint;
    }

    /**
     * Generate Delete Endpoint Documentation
     * @return string
     */
    private function generateDeleteEndpoint()
    {
        $endpoint = "/** @OA\Delete(\n" .
               " *     path=\"/{$this->tableName}/{id}\",\n" .
               " *     summary=\"Delete {$this->modelName}\",\n" .
               " *     tags={\"{$this->modelName}\"},\n" .
               " *     security={{\"bearerAuth\":{}}},\n" .
               " *     @OA\Parameter(\n" .
               " *         name=\"id\",\n" .
               " *         in=\"path\",\n" .
               " *         required=true,\n" .
               " *         @OA\Schema(type=\"integer\")\n" .
               " *     ),\n" .
               " *     @OA\Response(\n" .
               " *         response=200,\n" .
               " *         description=\"{$this->modelName} deleted successfully\"\n" .
               " *     ),\n" .
               " *     @OA\Response(response=404, description=\"{$this->modelName} not found\")\n" .
               " * )\n*/";
        return $endpoint;
    }

    /**
     * Get Swagger type from database column
     * @param \yii\db\ColumnSchema $column
     * @return string
    */
    private function getSwaggerType($column)
    {
        $typeMap = [
            'tinyint' => 'integer',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'int' => 'integer',
            'bigint' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'decimal' => 'number',
            'char' => 'string',
            'varchar' => 'string',
            'text' => 'string',
            'mediumtext' => 'string',
            'longtext' => 'string',
            'date' => 'string',
            'datetime' => 'string',
            'timestamp' => 'string',
            'time' => 'string',
            'json' => 'object',
            'boolean' => 'boolean'
        ];

        return $typeMap[$column->type] ?? 'string';
    }

    /**
     * Get column description based on name and type
     * @param \yii\db\ColumnSchema $column
     * @return string
     */
    private function getColumnDescription($column)
    {
        $description = ucwords(str_replace('_', ' ', $column->name));
        if ($column->type === 'datetime' || $column->type === 'timestamp') 
        {
            $description .= " (Format: YYYY-MM-DD HH:mm:ss)";
        } elseif ($column->type === 'date') {
            $description .= " (Format: YYYY-MM-DD)";
        }
        return $description;
    }

    /**
     * Check if the table has file upload columns based on column type.
     * @return bool
     */
    private function hasFileUploadColumns()
    {
        foreach ($this->columns as $column) {
            if ($this->isFileUploadColumn($column)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a specific column is a file upload column based on its type.
     * @param \yii\db\ColumnSchema $column
     * @return bool
     */
    private function isFileUploadColumn($column)
    {
        $fileColumnTypes = ['blob', 'mediumblob', 'longblob', 'binary'];
        return in_array(strtolower($column->type), $fileColumnTypes, true);
    }
}