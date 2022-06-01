<?php
include_once APP . 'Model/WorkflowModules/WorkflowBaseModule.php';

class Module_enrich_event extends WorkflowBaseModule
{
    public $id = 'enrich-event';
    public $name = 'Enrich Event';
    public $description = 'Enrich all Attributes contained in the Event with the provided module.';
    public $icon = 'asterisk';
    public $inputs = 1;
    public $outputs = 1;
    public $params = [];

    private $Module;


    public function __construct()
    {
        parent::__construct();
        $this->Module = ClassRegistry::init('Module');
        $this->params = [
            [
                'type' => 'select',
                'label' => 'Modules',
                'options' => array_merge([''], Hash::combine($this->Module->getModules('Enrichment'), '{n}.name', '{n}.name')),
            ],
        ];
    }

    public function exec(array $node, WorkflowRoamingData $roamingData, array &$errors = []): bool
    {
        parent::exec($node, $roamingData, $errors);
        $params = $this->getParamsWithValues($node);
        if (empty($params['Modules']['value'])) {
            $errors[] = __('No enrichmnent module selected');
            return false;
        }

        $rData = $roamingData->getData();
        $event_id = $rData['Event']['id'];
        $options = [
            'user' => $roamingData->getUser(),
            'event_id' => $event_id,
            'modules' => [$params['Modules']['value']]
        ];
        if (!empty($rData['__conditionData']['Attribute.uuid'])) {
            $options['attribute_uuids'] = $rData['__conditionData']['Attribute.uuid'];
        }

        $this->Event = ClassRegistry::init('Event');
        $result = $this->Event->enrichment($options);
        $this->push_zmq([
            'Enriching event' => $event_id,
            'Attribute added' => $result
        ]);
        return true;
    }
}