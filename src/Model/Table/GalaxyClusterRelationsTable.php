<?php

namespace App\Model\Table;

use App\Model\Entity\Distribution;
use App\Model\Entity\User;
use App\Model\Table\AppTable;
use ArrayObject;
use Cake\Collection\CollectionInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Query;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

/**
 * @property GalaxyClusterRelationTag $GalaxyClusterRelationTag
 * @property GalaxyCluster $TargetCluster
 * @property SharingGroup $SharingGroup
 */
class GalaxyClusterRelationsTable extends AppTable
{
    public $useTable = 'galaxy_cluster_relations';

    public $recursive = -1;

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence(['referenced_galaxy_cluster_uuid'])
            ->add(
                'galaxy_cluster_uuid',
                'uuid',
                [
                    'rule' => 'uuid',
                    'message' => 'Please provide a valid RFC 4122 UUID'
                ]
            )
            ->add(
                'referenced_galaxy_cluster_uuid',
                'uuid',
                [
                    'rule' => 'uuid',
                    'message' => 'Please provide a valid RFC 4122 UUID'
                ]
            )
            ->add(
                'distribution',
                'inList',
                [
                    'rule' => ['inList', Distribution::ALL],
                    'message' => 'Options: ' . implode(', ', Distribution::DESCRIPTIONS)
                ]
            );

        return $validator;
    }

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('AuditLog');

        $this->belongsTo(
            'SourceCluster',
            [
                'className' => 'GalaxyClusters',
                'foreignKey' => 'galaxy_cluster_id',
            ]
        );

        $this->belongsTo(
            'TargetCluster',
            [
                'className' => 'GalaxyClusters',
                'foreignKey' => 'galaxy_cluster_id',
            ]
        );

        $this->belongsTo(
            'SharingGroup',
            [
                'className' => 'SharingGroups',
                'foreignKey' => 'sharing_group_id'
            ]
        );

        $this->hasMany(
            'GalaxyClusterRelationTags',
            [
                'dependent' => true,
                'propertyName' => 'Tag',
            ]
        );
    }

    public function beforeFind(EventInterface $event, Query $query, ArrayObject $options)
    {
        $query->formatResults(
            function (CollectionInterface $results) {
                return $results->map(
                    function ($row) {
                        if (isset($row['TargetCluster']) && key_exists('id', $row['TargetCluster']) && is_null($row['TargetCluster']['id'])) {
                            $row['TargetCluster'] = [];
                        }
                        if (isset($row['GalaxyClusterRelation']['distribution']) && $row['GalaxyClusterRelation']['distribution'] != 4) {
                            unset($row['SharingGroup']);
                        }
                        return $row;
                    }
                );
            },
            $query::APPEND
        );
    }

    public function buildConditions($user, $clusterConditions = true)
    {
        $conditions = [];
        if (!$user['Role']['perm_site_admin']) {
            $alias = $this->alias;
            $SharingGroupsTable = $this->fetchTable('SharingGroups');
            $sgids = $SharingGroupsTable->authorizedIds($user);
            $gcOwnerIds = $this->SourceCluster->cacheGalaxyClusterOwnerIDs($user);
            $conditionsRelations['AND']['OR'] = [
                "$alias.galaxy_cluster_id" => $gcOwnerIds,
                [
                    'AND' => [
                        "$alias.distribution >" => 0,
                        "$alias.distribution <" => 4
                    ],
                ],
                [
                    'AND' => [
                        "$alias.sharing_group_id" => $sgids,
                        "$alias.distribution" => 4
                    ]
                ]
            ];
            $conditionsSourceCluster = $clusterConditions ? $this->SourceCluster->buildConditions($user) : [];
            $conditions = [
                'AND' => [
                    $conditionsRelations,
                    $conditionsSourceCluster
                ]
            ];
        }
        return $conditions;
    }

    public function fetchRelations($user, $options, $full = false)
    {
        $params = [
            'conditions' => $this->buildConditions($user),
            'recursive' => -1
        ];
        if (!empty($options['contain'])) {
            $params['contain'] = $options['contain'];
        } elseif ($full) {
            $params['contain'] = ['SharingGroup', 'SourceCluster', 'TargetCluster'];
        }
        if (empty($params['contain'])) {
            $params['contain'] = ['SourceCluster'];
        }
        if (!in_array('SourceCluster', $params['contain'])) {
            $params['contain'][] = 'SourceCluster';
        }
        if (isset($options['fields'])) {
            $params['fields'] = $options['fields'];
        }
        if (isset($options['conditions'])) {
            $params['conditions']['AND'][] = $options['conditions'];
        }
        if (isset($options['group'])) {
            $params['group'] = empty($options['group']) ? $options['group'] : false;
        }
        $relations = $this->find('all', $params);
        return $relations;
    }

    public function getExistingRelationships()
    {
        $existingRelationships = $this->find(
            'column',
            [
                'recursive' => -1,
                'fields' => ['referenced_galaxy_cluster_type'],
                'unique' => true,
            ]
        )->disableHydration()->toArray();
        $ObjectRelationshipsTable = $this->fetchTable('ObjectRelationships');
        $objectRelationships = $ObjectRelationshipsTable->find(
            'column',
            [
                'recursive' => -1,
                'fields' => ['name'],
                'unique' => true,
            ]
        )->disableHydration()->toArray();
        return array_unique(array_merge($existingRelationships, $objectRelationships));
    }

    /**
     * saveRelations
     *
     * @see saveRelation
     * @return array List of errors if any
     */
    public function saveRelations(array $user, array $cluster, array $relations, $captureTag = false, $force = false)
    {
        $errors = [];
        foreach ($relations as $k => $relation) {
            $saveResult = $this->saveRelation($user, $cluster, $relation, $captureTag = $captureTag, $force = $force);
            $errors = array_merge($errors, $saveResult);
        }
        return $errors;
    }

    /**
     * saveRelation Respecting ACL saves a relation and set correct fields where applicable.
     * Contrary to its capture equivalent, trying to save a relation for a unknown target cluster will fail.
     *
     * @param  User $user
     * @param  array $cluster       The cluster from which the relation is originating
     * @param  array $relation      The relation to save
     * @param  bool  $captureTag    Should the tag be captured if it doesn't exists
     * @param  bool  $force         Should the relation be edited if it exists
     * @return array List errors if any
     */
    public function saveRelation(User $user, array $cluster, array $relation, $captureTag = false, $force = false)
    {
        $errors = [];
        if (!isset($relation['GalaxyClusterRelation']) && !empty($relation)) {
            $relation = ['GalaxyClusterRelation' => $relation];
        }
        $authorizationCheck = $this->SourceCluster->fetchIfAuthorized($user, $cluster, ['edit'], $throwErrors = false, $full = false);
        if (isset($authorizationCheck['authorized']) && !$authorizationCheck['authorized']) {
            $errors[] = $authorizationCheck['error'];
            return $errors;
        }
        $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $cluster['uuid'];

        $existingRelation = $this->find(
            'all',
            [
                'conditions' => [
                    'galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'],
                    'referenced_galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'],
                    'referenced_galaxy_cluster_type' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_type'],
                ],
                'fields' => ['id'],
                'recursive' => -1,
            ]
        )->all();
        if (!empty($existingRelation->count())) {
            if (!$force) {
                $errors[] = __('Relation already exists');
                return $errors;
            } else {
                $relation['GalaxyClusterRelation']['id'] = $existingRelation['id'];
            }
        }
        if (empty($errors)) {
            if (!isset($relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'])) {
                $errors[] = __('referenced_galaxy_cluster_uuid not provided');
                return $errors;
            }
            if (!$force) {
                $targetCluster = $this->TargetCluster->fetchIfAuthorized($user, $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], 'view', $throwErrors = false, $full = false);
                if (isset($targetCluster['authorized']) && !$targetCluster['authorized']) { // do not save the relation if referenced cluster is not accessible by the user (or does not exist)
                    $errors[] = [__('Invalid referenced galaxy cluster')];
                    return $errors;
                }
            }
            $relation = $this->syncUUIDsAndIDs($user, $relation);
            $relationEntity = $this->newEntity($relation['GalaxyClusterRelation']);

            try {
                $this->saveOrFail($relationEntity);
                $relationEntity = $this->find(
                    'all',
                    [
                        'conditions' => ['id' =>  $relationEntity->id],
                        'recursive' => -1
                    ]
                )->first();
                $tags = [];
                if (!empty($relation['GalaxyClusterRelation']['tags'])) {
                    $tags = $relation['GalaxyClusterRelation']['tags'];
                } elseif (!empty($relation['GalaxyClusterRelation']['GalaxyClusterRelationTag'])) {
                    $tags = $relation['GalaxyClusterRelation']['GalaxyClusterRelationTag'];
                    $tags = Hash::extract($tags, '{n}.name');
                } elseif (!empty($relation['GalaxyClusterRelation']['Tag'])) {
                    $tags = $relation['GalaxyClusterRelation']['Tag'];
                    $tags = Hash::extract($tags, '{n}.name');
                }

                if (!empty($tags)) {
                    $tagSaveResults = $this->GalaxyClusterRelationTags->attachTags($user, $relationEntity->id, $tags, $capture = $captureTag);
                    if (!$tagSaveResults) {
                        $errors[] = __('Tags could not be saved for relation (%s)', $relationEntity->id);
                    }
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                return $errors;
            }

            $saveSuccess = $this->save($relationEntity);
        }
        return $errors;
    }

    /**
     * editRelation Respecting ACL edits a relation and set correct fields where applicable.
     * Contrary to its capture equivalent, trying to save a relation for a unknown target cluster will fail.
     *
     * @param  User $user
     * @param  array $relation      The relation to be saved
     * @param  array $fieldList     Only edit the fields provided
     * @param  bool  $captureTag    Should the tag be captured if it doesn't exists
     * @return array List of errors if any
     */
    public function editRelation(User $user, array $relation, array $fieldList = [], $captureTag = false)
    {
        $SharingGroupsTable = $this->fetchTable('SharingGroups');
        $errors = [];
        if (!isset($relation['GalaxyClusterRelation']['galaxy_cluster_id'])) {
            $errors[] = __('galaxy_cluster_id not provided');
            return $errors;
        }
        $authorizationCheck = $this->SourceCluster->fetchIfAuthorized($user, $relation['GalaxyClusterRelation']['galaxy_cluster_id'], ['edit'], $throwErrors = false, $full = false);
        if (isset($authorizationCheck['authorized']) && !$authorizationCheck['authorized']) {
            $errors[] = $authorizationCheck['error'];
            return $errors;
        }

        if (isset($relation['GalaxyClusterRelation']['id'])) {
            $existingRelation = $this->find('all', ['conditions' => ['GalaxyClusterRelations.id' => $relation['GalaxyClusterRelation']['id']]])->first();
        } else {
            $errors[] = __('UUID not provided');
        }
        if (empty($existingRelation)) {
            $errors[] = __('Unkown ID');
        } else {
            $options = [
                'conditions' => [
                    'uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid']
                ]
            ];
            $cluster = $this->SourceCluster->fetchGalaxyClusters($user, $options);
            if (empty($cluster)) {
                $errors[] = __('Invalid source galaxy cluster');
            }
            $cluster = $cluster[0];
            $relation['GalaxyClusterRelation']['id'] = $existingRelation['id'];
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $cluster['id'];
            $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $cluster['uuid'];

            if (isset($relation['GalaxyClusterRelation']['distribution']) && $relation['GalaxyClusterRelation']['distribution'] == 4 && !$SharingGroupsTable->checkIfAuthorised($user, $relation['GalaxyClusterRelation']['sharing_group_id'])) {
                $errors[] = [__('Galaxy Cluster Relation could not be saved: The user has to have access to the sharing group in order to be able to edit it.')];
            }

            if (empty($errors)) {
                if (!isset($relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'])) {
                    $errors[] = __('referenced_galaxy_cluster_uuid not provided');
                    return $errors;
                }
                $targetCluster = $this->TargetCluster->fetchIfAuthorized($user, $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], 'view', $throwErrors = false, $full = false);
                if (isset($targetCluster['authorized']) && !$targetCluster['authorized']) { // do not save the relation if referenced cluster is not accessible by the user (or does not exist)
                    $errors[] = [__('Invalid referenced galaxy cluster')];
                    return $errors;
                }
                $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $targetCluster['id'];
                $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'] = $targetCluster['uuid'];
                $relation['GalaxyClusterRelation']['default'] = false;
                if (empty($fieldList)) {
                    $fieldList = ['galaxy_cluster_id', 'galaxy_cluster_uuid', 'referenced_galaxy_cluster_id', 'referenced_galaxy_cluster_uuid', 'referenced_galaxy_cluster_type', 'distribution', 'sharing_group_id', 'default'];
                }
                $relationEntity = $this->patchEntity($existingRelation, $relation['GalaxyClusterRelation'], ['fieldList' => $fieldList, 'associated' => []]);
                try {
                    $this->saveOrFail($relationEntity);
                    $this->GalaxyClusterRelationTags->deleteAll(['GalaxyClusterRelationTag.galaxy_cluster_relation_id' => $relation['GalaxyClusterRelation']['id']]);
                    $this->GalaxyClusterRelationTags->attachTags($user, $relation['GalaxyClusterRelation']['id'], $relation['GalaxyClusterRelation']['tags'], $capture = $captureTag);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        return $errors;
    }

    public function bulkSaveRelations(array $relations)
    {
        // Fetch existing tags Name => ID mapping
        $tagNameToId = $this->GalaxyClusterRelationTags->Tags->find(
            'list',
            [
                'fields' => ['Tags.name', 'Tags.id'],
                'callbacks' => false,
            ]
        )->toArray();

        // Fetch all cluster UUID => ID mapping
        $galaxyClusterUuidToId =  $this->TargetCluster->find(
            'list',
            [
                'fields' => ['uuid', 'id'],
                'callbacks' => false,
            ]
        )->toArray();

        $lookupSavedIds = [];
        $relationTagsToSave = [];
        $TagsTable = $this->fetchTable('Tags');
        foreach ($relations as &$relation) {
            if (isset($galaxyClusterUuidToId[$relation['referenced_galaxy_cluster_uuid']])) {
                $relation['referenced_galaxy_cluster_id'] = $galaxyClusterUuidToId[$relation['referenced_galaxy_cluster_uuid']];
            } else {
                $relation['referenced_galaxy_cluster_id'] = 0; // referenced cluster doesn't exists
            }
            if (!empty($relation['tags'])) {
                $lookupSavedIds[$relation['galaxy_cluster_id']] = true;
                foreach ($relation['tags'] as $tag) {
                    if (!isset($tagNameToId[$tag])) {
                        $tagNameToId[$tag] = $TagsTable->quickAdd($tag);
                    }
                    $relationTagsToSave[$relation['galaxy_cluster_uuid']][$relation['referenced_galaxy_cluster_uuid']][] = $tagNameToId[$tag];
                }
            }
        }
        unset($galaxyClusterUuidToId, $tagNameToId);

        $this->saveMany($this->newEntities($relations), ['validate' => false]); // Some clusters uses invalid UUID :/

        // Insert tags
        $savedRelations = $this->find(
            'all',
            [
                'recursive' => -1,
                'conditions' => ['galaxy_cluster_id IN' => array_keys($lookupSavedIds)],
                'fields' => ['id', 'galaxy_cluster_uuid', 'referenced_galaxy_cluster_uuid']
            ]
        );
        $relation_tags = [];
        foreach ($savedRelations as $savedRelation) {
            $uuid1 = $savedRelation['galaxy_cluster_uuid'];
            $uuid2 = $savedRelation['referenced_galaxy_cluster_uuid'];
            if (isset($relationTagsToSave[$uuid1][$uuid2])) {
                foreach ($relationTagsToSave[$uuid1][$uuid2] as $tagId) {
                    $relation_tags[] = [
                        'galaxy_cluster_relation_id' => $savedRelation['id'],
                        'tag_id' => $tagId
                    ];
                }
            }
        }
        if (!empty($relation_tags)) {
            $this->GalaxyClusterRelationTags->saveMany($this->GalaxyClusterRelationTags->newEntities($relation_tags));
        }
    }

    /**
     * Gets a relation then save it.
     *
     * @param User $user
     * @param array $cluster    The cluster for which the relation is being saved
     * @param array $relation   The relation to be saved
     * @param bool  $fromPull   If the current capture is performed from a PULL sync. If set, it allows edition of existing relations
     * @return array The capture success results
     */
    public function captureRelations(User $user, array $cluster, array $relations, $fromPull = false)
    {
        $results = ['success' => false, 'imported' => 0, 'failed' => 0];
        $LogsTable = $this->fetchTable('Logs');
        $clusterUuid = $cluster['uuid'];
        $EventsTable = $this->fetchTable('Events');

        foreach ($relations as $k => $relation) {
            if (!isset($relation['GalaxyClusterRelation'])) {
                $relation = ['GalaxyClusterRelation' => $relation];
            }
            $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $clusterUuid;
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $cluster['id'];

            if (empty($relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'])) {
                $LogsTable->createLogEntry($user, 'captureRelations', 'GalaxyClusterRelation', 0, __('No referenced cluster UUID provided'), __('relation for cluster (%s)', $clusterUuid));
                $results['failed']++;
                continue;
            } else {
                $options = [
                    'conditions' => [
                        'uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'],
                    ],
                    'fields' => [
                        'id', 'uuid',
                    ]
                ];
                $referencedCluster = $this->SourceCluster->fetchGalaxyClusters($user, $options);
                if (empty($referencedCluster)) {
                    if (!$fromPull) {
                        $LogsTable->createLogEntry($user, 'captureRelations', 'GalaxyClusterRelation', 0, __('Referenced cluster not found'), __('relation to (%s) for cluster (%s)', $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], $clusterUuid));
                    }
                    $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = 0;
                } else {
                    $referencedCluster = $referencedCluster[0];
                    $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $referencedCluster['id'];
                }
            }

            $existingRelation = $this->find(
                'all',
                [
                    'conditions' => [
                        'GalaxyClusterRelations.galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'],
                        'GalaxyClusterRelations.referenced_galaxy_cluster_uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'],
                        'GalaxyClusterRelations.referenced_galaxy_cluster_type' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_type'],
                    ]
                ]
            )->first();
            if (!empty($existingRelation)) {
                if (!$fromPull) {
                    $LogsTable->createLogEntry($user, 'captureRelations', 'GalaxyClusterRelation', 0, __('Relation already exists'), __('relation to (%s) for cluster (%s)', $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'], $clusterUuid));
                    $results['failed']++;
                    continue;
                } else {
                    $relation['GalaxyClusterRelation']['id'] = $existingRelation['id'];
                }
            } else {
                unset($relation['GalaxyClusterRelation']['id']);
            }


            if (isset($relation['GalaxyClusterRelation']['distribution']) && $relation['GalaxyClusterRelation']['distribution'] == 4) {
                $relation['GalaxyClusterRelation'] = $EventsTable->captureSGForElement($relation['GalaxyClusterRelation'], $user);
            }

            $galaxyClusterRelationEntity = $this->newEntity($relation['GalaxyClusterRelation'], ['associated' => []]);
            $result = $this->save($galaxyClusterRelationEntity);
            if ($result) {
                $results['imported']++;
                $modelKey = false;
                if (!empty($relation['GalaxyClusterRelation']['GalaxyClusterRelationTag'])) {
                    $modelKey = 'GalaxyClusterRelationTag';
                } elseif (!empty($relation['GalaxyClusterRelation']['Tag'])) {
                    $modelKey = 'Tag';
                }
                if ($modelKey !== false) {
                    $tagNames = Hash::extract($relation['GalaxyClusterRelation'][$modelKey], '{n}.name');
                    // Similar behavior as for AttributeTags: Here we only attach tags. If they were removed at some point it's not taken into account.
                    // Since we don't have tag soft-deletion, tags added by users will be kept.
                    $this->GalaxyClusterRelationTags->attachTags($user, $galaxyClusterRelationEntity->id, $tagNames, $capture = true);
                }
            } else {
                $results['failed']++;
            }
        }

        $results['success'] = $results['imported'] > 0;
        return $results;
    }

    public function removeNonAccessibleTargetCluster($user, $relations)
    {
        $availableTargetClusterIDs = $this->TargetCluster->cacheGalaxyClusterIDs($user);
        $availableTargetClusterIDsKeyed = array_flip($availableTargetClusterIDs);
        foreach ($relations as $i => $relation) {
            if (
                isset($relation['TargetCluster']['id']) &&
                !isset($availableTargetClusterIDsKeyed[$relation['TargetCluster']['id']])
            ) {
                $relations[$i]['TargetCluster'] = null;
            }
        }
        return $relations;
    }

    /**
     * syncUUIDsAndIDs Adapt IDs of source and target cluster inside the relation based on the provided two UUIDs
     *
     * @param  User $user
     * @param  array $relation
     * @return array The adpated relation
     */
    private function syncUUIDsAndIDs(User $user, array $relation)
    {
        $options = [
            'conditions' => [
                'uuid' => $relation['GalaxyClusterRelation']['galaxy_cluster_uuid']
            ]
        ];
        $sourceCluster = $this->SourceCluster->fetchGalaxyClusters($user, $options);
        if (!empty($sourceCluster)) {
            $sourceCluster = $sourceCluster[0];
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $sourceCluster['id'];
            $relation['GalaxyClusterRelation']['galaxy_cluster_uuid'] = $sourceCluster['uuid'];
        }
        $options = [
            'conditions' => [
                'uuid' => $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid']
            ]
        ];
        $targetCluster = $this->TargetCluster->fetchGalaxyClusters($user, $options);
        if (!empty($targetCluster)) {
            $targetCluster = $targetCluster[0];
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $targetCluster['id'];
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_uuid'] = $targetCluster['uuid'];
        } else {
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = 0;
        }
        return $relation;
    }
}
