<?php

namespace App\Lib\Tools;

use App\Model\Entity\Distribution;

class EventTimelineTool
{
    private $__lookupTables = [];
    private $__user = false;
    private $__json = [];
    private $__eventModel;
    private $__refModel = false;
    # Will be use latter on
    private $__related_events = [];
    private $__related_attributes = [];
    # Feature not implemented yet.
    # This is to let user configure a polling frequency. The idea is that it's not because we don't have data during a specific period that it means it should be considered as positive (or fp).
    # For example, if we poll every Monday. If it was positive on week 1 and negative on week 2, the timeline will show that it was positive until monday on week 2, which is most probably incorrect as it might be actually negative on Wednesday.
    private $extrapolateWithPollingFrequency = false;
    private $__objectTemplateModel;
    private $__filterRules;
    private $__extended_view = 0;

    public function construct($eventModel, $user, $filterRules, $extended_view = 0)
    {
        $this->__eventModel = $eventModel;
        $this->__objectTemplateModel = $eventModel->Object->ObjectTemplate;
        $this->__user = $user;
        $this->__filterRules = $filterRules;
        $this->__json = [];
        $this->__extended_view = $extended_view;
        $this->__lookupTables = [
            'analysisLevels' => $this->__eventModel->analysisLevels,
            'distributionLevels' => Distribution::DESCRIPTIONS
        ];
        return true;
    }

    public function construct_for_ref($refModel, $user)
    {
        $this->__refModel = $refModel;
        $this->__user = $user;
        $this->__json = [];
        return true;
    }

    private function __get_event($id)
    {
        $fullevent = $this->__eventModel->fetchEvent($this->__user, ['eventid' => $id, 'flatten' => 0, 'includeTagRelations' => 1, 'extended' => $this->__extended_view]);
        $event = [];
        if (empty($fullevent)) {
            return $event;
        }

        if (!empty($fullevent[0]['Object'])) {
            $event['Object'] = $fullevent[0]['Object'];
        } else {
            $event['Object'] = [];
        }

        if (!empty($fullevent[0]['Attribute'])) {
            $event['Attribute'] = $fullevent[0]['Attribute'];
        } else {
            $event['Attribute'] = [];
        }

        if (!empty($fullevent[0]['Sighting'])) {
            $event['Sighting'] = $fullevent[0]['Sighting'];
        } else {
            $event['Sighting'] = [];
        }

        return $event;
    }

    public function get_timeline($id)
    {
        $event = $this->__get_event($id);
        $this->__json['items'] = [];

        if (empty($event)) {
            return $this->__json;
        }

        if (!empty($event['Object'])) {
            $object = $event['Object'];
        } else {
            $object = [];
        }

        if (!empty($event['Attribute'])) {
            $attribute = $event['Attribute'];
        } else {
            $attribute = [];
        }

        $sightingsAttributeMap = [];
        foreach ($event['Sighting'] as $sighting) {
            $sightingsAttributeMap[$sighting['attribute_id']][] = $sighting['date_sighting'];
        }

        // extract links and node type
        foreach ($attribute as $attr) {
            $toPush = [
                'id' => $attr['id'],
                'uuid' => $attr['uuid'],
                'content' => h($attr['value']),
                'event_id' => $attr['event_id'],
                'group' => 'attribute',
                'timestamp' => $attr['timestamp'],
                'first_seen' => $attr['first_seen'],
                'last_seen' => $attr['last_seen'],
                'attribute_type' => $attr['type'],
                'date_sighting' => $sightingsAttributeMap[$attr['id']] ?? [],
                'is_image' => $this->__eventModel->Attribute->isImage($attr),
            ];
            $this->__json['items'][] = $toPush;
        }

        foreach ($object as $obj) {
            $toPush_obj = [
                'id' => $obj['id'],
                'uuid' => $obj['uuid'],
                'content' => h($obj['name']),
                'group' => 'object',
                'meta-category' => h($obj['meta-category']),
                'template_uuid' => $obj['template_uuid'],
                'event_id' => $obj['event_id'],
                'timestamp' => $obj['timestamp'],
                'Attribute' => [],
            ];

            $toPush_obj['first_seen'] = $obj['first_seen'];
            $toPush_obj['last_seen'] = $obj['last_seen'];
            $toPush_obj['first_seen_overwrite'] = false;
            $toPush_obj['last_seen_overwrite'] = false;

            foreach ($obj['Attribute'] as $obj_attr) {
                // replaced *_seen based on object attribute
                if ($obj_attr['object_relation'] == 'first-seen' && is_null($toPush_obj['first_seen'])) {
                    $toPush_obj['first_seen'] = $obj_attr['value']; // replace first_seen of the object to seen of the element
                    $toPush_obj['first_seen_overwrite'] = true;
                } elseif ($obj_attr['object_relation'] == 'last-seen' && is_null($toPush_obj['last_seen'])) {
                    $toPush_obj['last_seen'] = $obj_attr['value']; // replace last_seen of the object to seen of the element
                    $toPush_obj['last_seen_overwrite'] = true;
                }
                $toPush_attr = [
                    'id' => $obj_attr['id'],
                    'uuid' => $obj_attr['uuid'],
                    'content' => h($obj_attr['value']),
                    'contentType' => h($obj_attr['object_relation']),
                    'event_id' => $obj_attr['event_id'],
                    'group' => 'object_attribute',
                    'timestamp' => $obj_attr['timestamp'],
                    'attribute_type' => $obj_attr['type'],
                    'date_sighting' => $sightingsAttributeMap[$obj_attr['id']] ?? [],
                    'is_image' => $this->__eventModel->Attribute->isImage($obj_attr),
                ];
                $toPush_obj['Attribute'][] = $toPush_attr;
            }
            $this->__json['items'][] = $toPush_obj;
        }

        return $this->__json;
    }

    /*
         * Extrapolation strategy:
         *  - If only positive sightings: Will be from first to last sighting
         *  - If both positive and false positive: False positive get priority. It will be marked as false positive until next positive sighting
        */
    public function get_sighting_timeline($id)
    {
        $event = $this->__eventModel->fetchEvent(
            $this->__user,
            [
                'eventid' => $id,
                'flatten' => 1,
                'includeTagRelations' => 1,
                'extended' => $this->__extended_view
            ]
        );
        $this->__json['items'] = [];

        if (empty($event)) {
            return $this->__json;
        } else {
            $event = $event[0];
        }

        $lookupAttribute = [];
        foreach ($event['Attribute'] as $k => $attribute) {
            $lookupAttribute[$attribute['id']] = &$event['Attribute'][$k];
        }

        // regroup sightings per attribute
        $regroupedSightings = [];
        foreach ($event['Sighting'] as $k => $sighting) {
            $event['Sighting'][$k]['date_sighting'] *= 1000; // adapt to use micro
            $regroupedSightings[$sighting['attribute_id']][] = &$event['Sighting'][$k];
        }
        // generate extrapolation
        $now = time() * 1000;
        foreach ($regroupedSightings as $attributeId => $sightings) {
            usort(
                $sightings,
                function ($a, $b) {
                // make sure sightings are ordered
                    return $a['date_sighting'] > $b['date_sighting'];
                }
            );
            $i = 0;
            while ($i < count($sightings)) {
                $sighting = $sightings[$i];
                $attribute = $lookupAttribute[$attributeId];
                $fpSightingIndex = $this->getNextFalsePositiveSightingIndex($sightings, $i + 1);
                $group = $sighting['type'] == '1' ? 'sighting_negative' : 'sighting_positive';
                if ($fpSightingIndex === false) { // No next FP, extrapolate to now
                    $this->__json['items'][] = [
                        'attribute_id' => $attributeId,
                        'id' => sprintf('%s-%s', $attributeId, $sighting['id']),
                        'uuid' => $sighting['uuid'],
                        'content' => h($attribute['value']),
                        'event_id' => $attribute['event_id'],
                        'group' => $group,
                        'timestamp' => $attribute['timestamp'],
                        'first_seen' => $sighting['date_sighting'],
                        'last_seen' => $now,
                    ];
                    break;
                } else {
                    // set up until last positive
                    $pSightingIndex = $fpSightingIndex - 1;
                    $halfTime = 0;
                    if ($pSightingIndex == $i) {
                        // we have only one positive sighting, thus the UP time should be take from a pooling frequence (feature not existing yet)
                        // for now, consider it UP only for half the time until the next FP
                        if ($this->extrapolateWithPollingFrequency) {
                            $halfTime = ($sightings[$i + 1]['date_sighting'] - $sighting['date_sighting']) / 2;
                        }
                    }
                    $pSighting = $sightings[$pSightingIndex];
                    if ($this->extrapolateWithPollingFrequency) {
                        $lastSeenPositive = $pSighting['date_sighting'] + $halfTime;
                    } else {
                        $lastSeenPositive = $sightings[$i + 1]['date_sighting'];
                    }
                    $this->__json['items'][] = [
                        'attribute_id' => $attributeId,
                        'id' => sprintf('%s-%s', $attributeId, $sighting['id']),
                        'uuid' => $sighting['uuid'],
                        'content' => h($attribute['value']),
                        'event_id' => $attribute['event_id'],
                        'group' => 'sighting_positive',
                        'timestamp' => $attribute['timestamp'],
                        'first_seen' => $sighting['date_sighting'],
                        'last_seen' => $lastSeenPositive,
                    ];
                    // No next FP, extrapolate to now
                    $fpSighting = $sightings[$fpSightingIndex];
                    $secondNextPSightingIndex = $this->getNextPositiveSightingIndex($sightings, $fpSightingIndex + 1);
                    if ($secondNextPSightingIndex === false) { // No next P, extrapolate to now
                        if ($this->extrapolateWithPollingFrequency) {
                            $firstSeenNegative = $fpSighting['date_sighting'] - $halfTime;
                        } else {
                            $firstSeenNegative = $fpSighting['date_sighting'];
                        }
                        $this->__json['items'][] = [
                            'attribute_id' => $attributeId,
                            'id' => sprintf('%s-%s', $attributeId, $sighting['id']),
                            'uuid' => $fpSighting['uuid'],
                            'content' => h($attribute['value']),
                            'event_id' => $attribute['event_id'],
                            'group' => 'sighting_negative',
                            'timestamp' => $attribute['timestamp'],
                            'first_seen' => $firstSeenNegative,
                            'last_seen' => $now,
                        ];
                        break;
                    } else {
                        if ($halfTime > 0) { // We need to fake a previous P
                            $pSightingIndex = $pSightingIndex + 1;
                            $pSighting = $sightings[$pSightingIndex];
                        }
                        if ($this->extrapolateWithPollingFrequency) {
                            $firstSeenNegative = $fpSighting['date_sighting'] - $halfTime;
                        } else {
                            $firstSeenNegative = $fpSighting['date_sighting'];
                        }
                        // set down until next postive
                        $secondNextPSighting = $sightings[$secondNextPSightingIndex];
                        $this->__json['items'][] = [
                            'attribute_id' => $attributeId,
                            'id' => sprintf('%s-%s', $attributeId, $sighting['id']),
                            'uuid' => $fpSighting['uuid'],
                            'content' => h($attribute['value']),
                            'event_id' => $attribute['event_id'],
                            'group' => 'sighting_negative',
                            'timestamp' => $attribute['timestamp'],
                            'first_seen' => $firstSeenNegative,
                            'last_seen' => $secondNextPSighting['date_sighting'],
                        ];
                        $i = $secondNextPSightingIndex;
                    }
                }
            }
        }
        return $this->__json;
    }

    private function getNextFalsePositiveSightingIndex($sightings, $startIndex)
    {
        for ($i = $startIndex; $i < count($sightings); $i++) {
            $sighting = $sightings[$i];
            if ($sighting['type'] == 1) { // is false positive
                return $i;
            }
        }
        return false;
    }
    private function getNextPositiveSightingIndex($sightings, $startIndex)
    {
        for ($i = $startIndex; $i < count($sightings); $i++) {
            $sighting = $sightings[$i];
            if ($sighting['type'] == 0) { // is false positive
                return $i;
            }
        }
        return false;
    }
}
