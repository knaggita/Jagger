<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/**
 * ResourceRegistry3
 *
 * @package     RR3
 * @author      Middleware Team HEAnet
 * @copyright   Copyright (c) 2012, HEAnet Limited (http://www.heanet.ie)
 * @license     MIT http://www.opensource.org/licenses/mit-license.php
 *
 */

/**
 * J_queue Class
 *
 * @package     RR3
 * @subpackage  Libraries
 * @author      Janusz Ulanowski <janusz.ulanowski@heanet.ie>
 */
class J_queue
{

    private $ci;
    /**
     * @var $em Doctrine\ORM\EntityManager
     */
    protected $em;
    private $tmp_providers;
    private $tmp_federations;
    private $attributesByName;

    public function __construct() {
        $this->ci = &get_instance();
        $this->em = $this->ci->doctrine->em;
        $this->tmp_providers = new models\Providers;
        $this->tmp_federations = new models\Federations;
        /**
         * @var $attrs models\Attribute[]
         */
        $attrs = $this->em->getRepository("models\Attribute")->findAll();
        foreach ($attrs as $a) {
            $this->attributesByName['' . $a->getOid() . ''] = $a;
        }
        $this->ci->load->library('table');
    }

    /**
     * @param $qid
     * @param bool $onlycancel
     * @return string
     */
    public function displayFormsButtons($qid, $onlycancel = false) {
        /* add approve form */
        $approveForm = '';
        $rejecttext = lang('rr_cancel');
        if (!$onlycancel) {
            $rejecttext = lang('rr_submitreject');
            $approveForm = form_open('reports/awaiting/approve', array('id' => 'approvequeue'), array('qaction' => 'approve', 'qid' => $qid, 'setfederation' => 'yes')) .
                '<button type="submit" name="mysubmit" value="Accept request!" class="button savebutton saveicon right">' . lang('rr_submitapprove') . '</button>' . form_close();
        }

        /* add reject form */
        $rejectHiddenAttrs = array('qaction' => 'reject', 'qid' => $qid);
        $reject_attrid = array('id' => 'rejectqueue');
        $rejectForm = form_open('reports/queueactions/reject', $reject_attrid, $rejectHiddenAttrs) .
            '<button type="submit" name="mysubmit" value="Reject request!" class="button resetbutton reseticon left alert">' . $rejecttext . '</button>' . form_close();

        $result = '<div class="small-12  columns"><div class="small-6 column" >' . $rejectForm . '</div><div class="small-6 column">' . $approveForm . '</div></div>';

        return $result;
    }

    /**
     * button to approve/reject idp/sp registration request
     *
     * @param $qid
     * @param bool $onlycancel
     * @return string
     */
    public function queueRegProviderButtons($qid, $onlycancel = false) {
        /* add approve form */
        $select = '<div class="row"><div class="medium-6 column"><select  name="accesslevel" ><option value="none">' . lang('rrdontassign') . '</option><option value="write">' . lang('rr_write') . '</option><option value="manage">' . lang('rr_management') . '</option></select></div><div class="medium-6 column"><label for="accesslevel">' . lang('lbl_setaccesslvl') . ' <span class="label">if not anonymous</span></label></div></div>';
        $approveForm = '';
        $rejecttext = lang('rr_cancel');
        if (!$onlycancel) {
            $rejecttext = lang('rr_submitreject');
            $approveForm = form_open('reports/awaiting/approve', array('id' => 'approvequeue'), array('qaction' => 'approve', 'qid' => $qid, 'setfederation' => 'yes')) .
                '<div class="small-12 column"><button type="submit" name="mysubmit" value="Accept request!" class="button savebutton saveicon right">' . lang('rr_submitapprove') . '</button></div>' .
                '<div>' . $select . '</div>' .
                form_close();
        }

        /* add reject form */
        $rejectHiddenAttrs = array('qaction' => 'reject', 'qid' => $qid);
        $reject_attrid = array('id' => 'rejectqueue');
        $rejectForm = form_open('reports/queueactions/reject', $reject_attrid, $rejectHiddenAttrs) .
            '<button type="submit" name="mysubmit" value="Reject request!" class="button resetbutton reseticon left alert">' . $rejecttext . '</button>' . form_close();
        $result = '<div class="small-12  columns"><div class="medium-6 column" >' . $rejectForm . '</div><div class="medium-6 column">' . $approveForm . '</div></div>';

        return $result;
    }

    public function createUserFromQueue(models\Queue $queue) {
        $objdata = $queue->getData();
        if (!is_array($objdata)) {
            log_message('error', __METHOD__ . ' data not in array');

            return false;
        }
        if (!isset($objdata['username'], $objdata['email'], $objdata['type'])) {
            log_message('error', __METHOD__ . ' data doesnt contain information about username/email');

            return false;
        }
        /**
         * @var models\User $checkuser
         */
        $checkuser = $this->em->createQuery("SELECT u FROM models\User u WHERE u.username = '{$objdata['username']}'")->getResult();
        if (!empty($checkuser)) {
            $this->ci->globalerrors[] = lang('useralredyregistered');
            $this->ci->globalerrors[] = lang('queremoved');
            log_message('error', __METHOD__ . ' User ' . $objdata['username'] . ' already exists, remove request from the queue with id: ' . $queue->getID());
            $this->em->remove($queue);
            $this->em->flush();

            return false;
        }
        $password = null;
        if (!empty($objdata['pass'])) {
            $password = trim($objdata['pass']);
        }
        $type = $objdata['type'];

        $user = new models\User;
        $user->genNewValidUser($objdata['username'], $password, $objdata['email'], null, null, $type);

        if (!empty($objdata['fname'])) {
            $user->setGivenname($objdata['fname']);
        }
        if (!empty($objdata['sname'])) {
            $user->setSurname($objdata['sname']);
        }

        /**
         * @var models\AclRole $member
         */
        $member = $this->em->getRepository("models\AclRole")->findOneBy(array('name' => 'Member'));
        if ($member !== null) {
            $user->setRole($member);
        }
        $personalRole = new models\AclRole;
        $personalRole->setName($user->getUsername());
        $personalRole->setType('user');
        $personalRole->setDescription('personal role for user ' . $user->getUsername());
        $user->setRole($personalRole);
        $this->em->persist($personalRole);
        $this->em->persist($user);

        $mailBody = 'Dear user,' . PHP_EOL .
            'User registration request to use the service ' . base_url() . ' has been accepted' . PHP_EOL .
            'Details:' . PHP_EOL . 'Username: ' . $user->getUsername() . PHP_EOL .
            'E-mail: ' . $user->getEmail() . PHP_EOL;
        $recipient[] = $user->getEmail();
        $this->ci->emailsender->addToMailQueue(array(), null, 'User Registration', $mailBody, $recipient, $sync = false);

        return true;
    }

    private function genCocArray(models\Queue $queue, $type) {
        $typeLabel = '';
        if ($type === 'entcat') {
            $result = array(
                array('header' => lang('request')),
                array('name' => lang('type'), 'value' => lang('req_entcatapply')),
                array('name' => lang('rr_sourceip'), 'value' => $queue->getIP())
            );
            $typeLabel = lang('entcat');
        } elseif ($type === 'regpol') {
            $result = array(
                array('header' => lang('request')),
                array('name' => lang('type'), 'value' => lang('req_reqpolapply')),
                array('name' => lang('rr_sourceip'), 'value' => $queue->getIP())
            );
            $typeLabel = lang('rr_regpolicy');
        }
        $creator = $queue->getCreator();
        if ($creator) {
            $result[] = array('name' => lang('requestor'), 'value' => $creator->getUsername());
        } else {
            $result[] = array('name' => lang('requestor'), 'value' => lang('unknown'));
        }
        $entityid = $queue->getName();
        $provider = $this->em->getRepository("models\Provider")->findOneBy(array('entityid' => $entityid));

        if (!empty($provider)) {
            $result[] = array('name' => lang('rr_provider'), 'value' => $entityid);
        } else {

            $result[] = array('name' => lang('rr_provider'), 'value' => $entityid . ' <span class="label alert">' . lang('prov_notexist') . '</span>');
        }
        $entcatid = $queue->getRecipient();
        /**
         * @var models\Coc $coc
         */
        $coc = $this->em->getRepository("models\Coc")->findOneBy(array('id' => $entcatid, 'type' => '' . $type . ''));

        if ($coc === null) {
            $result[] = array('name' => $typeLabel, 'value' => '<div data-alert class="alert-box alert">' . lang('regpol_notexist') . '</div>');
        } else {
            $lenabled = '';
            if (!$coc->getAvailable()) {
                $lenabled = '<span class="label alert">' . lang('rr_disabled') . '</span>';
            }
            if ($type === 'entcat') {
                $result[] = array(
                    'name'  => lang('attrname'),
                    'value' => html_escape($coc->getSubtype())
                );
            }
            $result[] = array('name' => $typeLabel, 'value' => '<span class="label info">' . $coc->getLang() . '</span> ' . $coc->getName() . ': ' . $coc->getUrl() . ' ' . $lenabled);
        }

        return $result;

    }


    public function displayApplyForEntityCategory(models\Queue $queue) {
        return $this->genCocArray($queue, 'entcat');
    }

    public function displayApplyForRegistrationPolicy(models\Queue $queue) {
        return $this->genCocArray($queue, 'regpol');
    }

    public function displayRegisterUser(models\Queue $queue) {
        $objdata = $queue->getData();
        $result = array(
            array('header' => lang('request')),
            array('name' => lang('type'), 'value' => lang('req_userregistration')),
            array('name' => lang('rr_regdate'), 'value' => $queue->getCreatedAt()),
            array('name' => lang('rr_fname'), 'value' => $objdata['fname']),
            array('name' => lang('rr_lname'), 'value' => $objdata['sname']),
            array('name' => lang('rr_uemail'), 'value' => $objdata['email']),
            array('name' => lang('rr_username'), 'value' => $queue->getName()),
            array('name' => lang('rr_sourceip'), 'value' => $queue->getIP()),
        );
        $creator = $queue->getCreator();
        if ($creator) {
            $result[] = array('name' => lang('requestor'), 'value' => $creator->getUsername());
        } else {
            $result[] = array('name' => lang('requestor'), 'value' => lang('unknown'));
        }
        if (isset($objdata['ip'])) {
            $result[] = array('name' => 'IP', 'value' => $objdata['ip']);
        }
        if (isset($objdata['type'])) {
            if ($objdata['type'] === 'federated') {
                $result[] = array('name' => 'Type of account', 'value' => '' . lang('rr_onlyfedauth') . '');
            } elseif ($objdata['type'] === 'local') {
                $result[] = array('name' => 'Type of account', 'value' => '' . lang('rr_onlylocalauthn') . '');
            } elseif ($objdata['type'] === 'both') {
                $result[] = array('name' => 'Type of account', 'value' => '' . lang('rr_bothauth') . '');
            } else {
                $result[] = array('name' => 'Type of account', 'value' => '<span class="alert">' . lang('unknown') . '</span>');
            }
        }


        return $result;
    }

    /**
     * @param \models\Queue $queue
     * @return array
     */
    public function displayRegisterFederation(models\Queue $queue) {
        $objData = new models\Federation;
        $objData->importFromArray($queue->getData());
        $creator = $queue->getCreator();
        $row1 = array('name' => lang('requestor'), 'value' => lang('unknown'));
        if ($creator !== null) {
            $row1 = array('name' => lang('requestor'), 'value' => $creator->getFullname() . ' (' . $creator->getUsername() . ')');
        }
        $fedrows = array(
            array('header' => lang('request')),
            array('name' => lang('type'), 'value' => lang('reqregnewfed')),
            $row1,
            array('name' => lang('rr_sourceip'), 'value' => $queue->getIP()),
            array('name' => lang('rr_regdate'), 'value' => $queue->getCreatedAt()),
            array('header' => lang('rr_basicinformation')),
            array('name' => lang('rr_fed_name'), 'value' => $objData->getName()),
            array('name' => lang('fednameinmeta'), 'value' => $objData->getUrn()),
            array('name' => lang('Description'), 'value' => $objData->getDescription()),
            array('name' => lang('rr_fed_tou'), 'value' => $objData->getTou())
        );

        return $fedrows;
    }

    public function displayUpdateProvider(models\Queue $queue) {
        $objData = $queue->getData();
        $creator = $queue->getCreator();
        /**
         * @var models\Provider $provider
         */
        $provider = $this->em->getRepository('models\Provider')->findOneBy(array('entityid' => $queue->getName(), 'type' => array('IDP', 'BOTH')));
        $approveaccess = $this->ci->jqueueaccess->hasApproveAccess($queue);
        $result = array(
            array('header' => lang('request')),
            array('name' => lang('rr_sourceip'), 'value' => $queue->getIP()),
            array('name' => lang('type'), 'value' => 'provider: ' . html_escape($queue->getName()) . ''),

        );
        if ($provider === null) {
            $result[] = array('2cols' => '<div data-alert class="alert-box alert" >' . lang('rerror_providernotexist') . '</div>');
            $buttons = $this->displayFormsButtons($queue->getId(), true);

        } else {
            $isLocal = $provider->getLocal();
            $isLocked = $provider->getLocked();
            if (!$isLocal) {
                $result[] = array('2cols' => '<div data-alert class="alert-box alert" >' . lang('rr_externalentity') . '</div>');
                $buttons = $this->displayFormsButtons($queue->getId(), true);
            } elseif ($isLocked) {
                $result[] = array('2cols' => '<div data-alert class="alert-box alert" >' . lang('error_lockednoedit') . '</div>');
                $buttons = $this->displayFormsButtons($queue->getId(), true);
            } else {
                $buttons = $this->displayFormsButtons($queue->getId(), !$approveaccess);
            }
        }
        $result[] = array('name' => lang('requestor'), 'value' => $creator->getFullname() . '<br /><b>' . lang('rr_username') . '</b>: ' . $creator->getUsername() . '');
        if (array_key_exists('scope', $objData)) {
            $result[] = array('header' => 'Request for update scope');
            $orig = '';
            if ($objData['scope']['orig']) {
                foreach ($objData['scope']['orig'] as $k => $v) {
                    foreach ($v as $w) {
                        $orig .= $k . ': ' . html_escape($w) . '<br />';
                    }
                }
            }
            $new = '';
            if ($objData['scope']['new']) {
                foreach ($objData['scope']['new'] as $k => $v) {
                    foreach ($v as $w) {
                        $new .= $k . ': ' . html_escape($w) . '<br />';
                    }
                }
            }
            $result[] = array('name' => 'Scope current', 'value' => $orig);
            $result[] = array('name' => 'Scope requested', 'value' => $new);

        }
        $result[] = array('2cols' => $buttons);

        return $result;
    }

    /**
     * @param \models\Queue $queue
     * @return array
     */
    public function displayDeleteFederation(models\Queue $queue) {
        $objData = new models\Federation;
        $objData->importFromArray($queue->getData());
        $creator = $queue->getCreator();
        $row1 = array('name' => lang('requestor'), 'value' => lang('unknown'));
        if ($creator) {
            $row1 = array('name' => lang('requestor'), 'value' => $creator->getUsername());
        }
        $fedrows = array(
            array('header' => lang('request')),
            array('name' => lang('type'), 'value' => lang('reqdelfed')),
            $row1,
            array('name' => lang('rr_sourceip'), 'value' => $queue->getIP()),
            array('name' => lang('rr_requestdate'), 'value' => $queue->getCreatedAt()),
            array('header' => lang('rr_basicinformation')),
            array('name' => lang('rr_fed_name'), 'value' => $objData->getName()),
            array('name' => lang('fednameinmeta'), 'value' => $objData->getUrn())
        );

        return $fedrows;
    }


    public function displayRegisterProvider(models\Queue $queue) {
        $showXML = false;

        $objData = null;
        $data = $queue->getData();
        $objData = new models\Provider;
        if (!isset($data['metadata'])) {
            $objData->importFromArray($data);
        } else {
            $metadataXml = base64_decode($data['metadata']);
            $this->ci->load->library('xmlvalidator');
            libxml_use_internal_errors(true);
            $metadataDOM = new \DOMDocument();
            $metadataDOM->strictErrorChecking = false;
            $metadataDOM->WarningChecking = false;
            $metadataDOM->loadXML($metadataXml);

            $isValid = $this->ci->xmlvalidator->validateMetadata($metadataDOM, false, false);
            if (!$isValid) {
                log_message('error', __METHOD__ . ' invalid metadata in the queue ');
            } else {
                $this->ci->load->library('metadata2array');
                $xpath = new DomXPath($metadataDOM);
                $namespaces = h_metadataNamespaces();
                foreach ($namespaces as $key => $value) {
                    $xpath->registerNamespace($key, $value);
                }
                /**
                 * @var DOMElement[] $domlist
                 */
                $domlist = $metadataDOM->getElementsByTagName('EntityDescriptor');
                if (count($domlist) === 1) {
                    foreach ($domlist as $l) {
                        $entarray = $this->ci->metadata2array->entityDOMToArray($l, true);
                    }
                    $objData = new models\Provider;
                    $objData->setProviderFromArray(current($entarray));
                    $objData->setReqAttrsFromArray(current($entarray), $this->attributesByName);
                    $metadataXML = $this->ci->providertoxml->entityConvertNewDocument($objData, array('attrs' => 1), true);
                    $showXML = true;
                }
            }
        }
        $feds = $objData->getFederations();
        $fedIdsCollection = array();
        $creatorUN = 'anonymous';
        $creatorFN = 'Anonymous';
        $creator = $queue->getCreator();
        if (null !== $creator) {
            $creatorUN = $creator->getUsername();
            $creatorFN = $creator->getFullname();
        }
        $dataRows[]['header'] = lang('rr_details');
        $dataRows[] = array( 'name' => lang('requestor'),'value' =>  ''.$creatorFN .'('.$creatorUN.')');
        $dataRows[] = array('name' => lang('rr_sourceip'), 'value' => $queue->getIP());
        $dataRows[]['header'] = lang('rr_fedstojoin');

        if ($feds->count() > 0) {

            foreach ($objData->getFederations() as $fed) {
                $realFed = $this->em->getRepository("models\Federation")->findOneBy(array('sysname' => $fed->getSysname()));
                if (!empty($realFed)) {
                    $fedIdsCollection[] = $realFed->getId();
                }
                $dataRows[] = array( 'name'=>''.lang('rr_federation').'','value' => ''.$fed->getName().'' );
            }
        } elseif (isset($data['federations'])) {
            foreach ($data['federations'] as $f) {
                $p = $this->em->getRepository("models\Federation")->findOneBy(array('sysname' => $f['sysname']));
                if (!empty($p)) {
                    $fedIdsCollection[] = $p->getId();
                    $dataRows[] = array('name'=>''.lang('rr_federation').'','value'=>''.$p->getName().'');
                }
            }
        } else {
            $dataRows[] = array('name' => '', 'value' => lang('noneatthemoment'));
        }

        /**
         * @todo show all fedvalidators which are assigned to federations
         */
        $valMandatory = null;
        $valOptional = null;
        $attrs = array('id' => 'fvform', 'style' => 'display: inline', 'class' => '');
        if (count($fedIdsCollection) > 0) {
            /**
             * @var models\FederationValidator[] $validators
             */
            $validators = $this->em->getRepository("models\FederationValidator")->findBy(array('federation' => $fedIdsCollection, 'isEnabled' => true));
            foreach ($validators as $v) {
                if ($v->getMandatory()) {
                    $hidden = array('fedid' => $v->getFederation()->getId(), 'qtoken' => $queue->getToken(), 'fvid' => $v->getId());
                    $valMandatory .= form_open(base_url() . 'federations/fvalidator/validate', $attrs, $hidden);
                    $valMandatory .= '<button class="button" id="' . $v->getId() . '" title="' . $v->getDescription() . '" name="mandatory">' . $v->getName() . '</button> ';
                    $valMandatory .= form_close();
                } else {
                    $hidden = array('fedid' => $v->getFederation()->getId(), 'qtoken' => $queue->getToken(), 'fvid' => $v->getId());
                    $valOptional .= form_open(base_url() . 'federations/fvalidator/validate', $attrs, $hidden);
                    $valOptional .= '<button class="button" id="' . $v->getId() . '" title="' . $v->getDescription() . '">' . $v->getName() . '</button> ';
                    $valOptional .= form_close();
                }
            }
            $dataRows[] = array('name' => lang('manValidator'), 'value' => $valMandatory);
            $dataRows[] = array('name' => lang('optValidator'), 'value' => $valOptional);
            $resultValidation = '<div id="fvresult" style="display:none;" data-alert class="alert-box info"><div><b>' . lang('fvalidcodereceived') . '</b>: <span id="fvreturncode"></span></div><div><p><b>' . lang('fvalidmsgsreceived') . '</b>:</p><div id="fvmessages"></div></div></div>';
            $resultValidation .= '<div id="fvalidesc"></div>';
            $dataRows[] = array('2cols' => $resultValidation);
        }
        $dataRows[]['header'] = lang('rr_basicinformation');
        $dataRows[] = array('name'=>''.lang('rr_homeorganisationname').'','value'=>''. $objData->getName().'');
        $dataRows[] = array('name'=>'entityID','value'=>''.$objData->getEntityId().'');

        $type = $objData->getType();
        $nameids = '';
        if ($type === 'IDP') {
            $nameids = implode(', ', $objData->getNameIds('idpsso'));
            $dataRows[] = array('name'=>''.lang('type').'','value'=>''.lang('identityprovider').'');
            $dataRows[] = array('name'=>''.lang('rr_scope') . ' <br /><small>IDPSSODescriptor</small>','value'=>''.implode(';', $objData->getScope('idpsso')).'');

        } elseif ($type === 'SP') {
            $nameids = implode(', ', $objData->getNameIds('spsso'));
            $dataRows[] = array('name'=>''.lang('type').'','value'=>''. lang('serviceprovider').'');
        }

        $dataRows[] =array( 'name'=>''.lang('rr_helpdeskurl').'','value'=>''.$objData->getHelpdeskUrl().'');

        $dataRows[] = array('header' => lang('rr_contacts'));

        foreach ($objData->getContacts() as $contact) {
            $phone = $contact->getPhone();
            $phoneStr = '';
            if (!empty($phone)) {
                $phoneStr = 'Tel:' . $phone;
            }
            $dataRows[] = array('name' => '' . lang('rr_contact') . ' (' . $contact->getType() . ')', 'value' => '' . $contact->getFullName() . ' &lt;' . $contact->getEmail() . '&gt; ' . $phoneStr . '');
        }



        $dataRows[]['header'] = lang('rr_servicelocations');
        $srvTypesWithIdx = array('IDPArtifactResolutionService', 'DiscoveryResponse', 'AssertionConsumerService', 'SPArtifactResolutionService');
        foreach ($objData->getServiceLocations() as $service) {
            $serviceType = $service->getType();
            $orderString = '';
            if (in_array($serviceType, $srvTypesWithIdx, true)) {
                $orderString = 'index: ' . $service->getOrder();
            }
            $dataRows[] = array('name'=>''.$serviceType.'','value'=>'' . $service->getUrl() . '<br /><small>' . $service->getBindingName() . ' ' . $orderString . ' </small><br />');
        }

        array_push($dataRows, array('header' => lang('rr_supportednameids')), array('name' => lang('nameid'), 'value' => $nameids), array('header' => lang('rr_certificates')));
        foreach ($objData->getCertificates() as $cert) {
            $certdatacell = reformatPEM($cert->getCertdata());
            $dataRows[] = array('name' => lang('rr_certificateuse') . ' <span class="label info">' . html_escape($cert->getCertUseInStr()) . '</span>', 'value' => '<span class="span-10"><code>' . $certdatacell . '</code></span>');
        }

        if ($showXML === true) {
            $dataRows[]['header'] = 'Metadata view';
            $dataRows[] = array('name' => 'XML', 'value' => '<pre><code class="xml">' . html_escape($metadataXML) . '</code></pre>');

        }

        return $dataRows;
    }

    public function displayInviteProvider(models\Queue $queue) {

        /**
         * @var models\Provider $provider
         */
        if ($queue->getRecipientType() === 'provider') {

            $provider = $this->tmp_providers->getOneById($queue->getRecipient());
        }
        if (empty($provider)) {
            log_message('error', __METHOD__ . ' entity with ID: ' . $queue->getRecipient() . ' not found in db');

            return false;
        }
        $tmpl = array('table_open' => '<table id="details" class="zebra">');
        $this->ci->table->set_template($tmpl);
        $this->ci->table->set_caption(lang('rr_requestawaiting'));


        $text = '<span style="white-space: normal">' . lang('adminoffed') . ': ' . $queue->getName() . ' ' . lang('invyourprov') . ': (' . $provider->getEntityId() . ')</span>';
        $this->ci->table->add_row(array('data' => $text, 'colspan' => 2));
        $this->ci->table->add_row(array('data' => lang('rr_details'), 'class' => 'highlight', 'colspan' => 2));
        $cell = array(lang('requestor'), $queue->getCreator()->getUsername() . ' (' . $queue->getCreator()->getFullname() . ') : email: ' . $queue->getCreator()->getEmail());
        $this->ci->table->add_row($cell);
        $this->ci->table->add_row(array(lang('rr_sourceip'), '' . $queue->getIP() . ''));
        $cell = array(lang('rr_federation'), $queue->getName());
        $this->ci->table->add_row($cell);
        $cell = array(lang('rr_provider'), $provider->getName());
        $this->ci->table->add_row($cell);
        $cell = array(lang('request'), lang('joinfederation'));
        $this->ci->table->add_row($cell);
        $cell = array('data' => $this->displayFormsButtons($queue->getID()), 'colspan' => 2);
        $this->ci->table->add_row($cell);
        $result = $this->ci->table->generate();
        $this->ci->table->clear();

        return $result;
    }

    /**
     * @param \models\Queue $queue
     * @param bool|false $canApprove
     * @return mixed
     */
    public function displayInviteFederation(models\Queue $queue, $canApprove = false) {


        $recipientType = $queue->getRecipientType();
        /**
         * @var models\Federation $federation
         */
        $federation = null;
        if (strcasecmp($recipientType, 'federation') == 0) {
            $federation = $this->tmp_federations->getOneFederationById($queue->getRecipient());
        }
        if ($federation === null) {
            \log_message('error', __METHOD__ . ' Federation (' . $queue->getRecipient() . ') does not exist anymore');

            return false;
        }
        $tmpl = array('table_open' => '<table id="details" class="zebra">');
        $this->ci->table->set_template($tmpl);
        $this->ci->table->set_caption(lang('rr_requestawaiting'));

        $text = '<span style="white-space: normal">' . lang('adminofprov') . ': ' . html_escape($queue->getName()) . ' ' . lang('askedyourfed') . ': (' . html_escape($federation->getName()) . ')</span>';

        $rows = array(
            array('data' => $text, 'colspan' => 2),
            array('data' => lang('rr_details'), 'class' => 'highlight', 'colspan' => 2),
            array(lang('requestor'), html_escape($queue->getCreator()->getFullname()) . ' (' . html_escape($queue->getCreator()->getUsername()) . ')'),
            array(lang('rr_sourceip'), $queue->getIP()),
        );


        $data = $queue->getData();
        /**
         * @var $provider models\Provider
         */
        $provider = $this->em->getRepository("models\Provider")->findOneBy(array('entityid' => $data['entityid']));
        $validators = $federation->getValidators();
        $valMandatory = '';
        $valOptional = '';
        $attrs = array('id' => 'fvform', 'style' => 'display: inline', 'class' => '');
        foreach ($validators as $v) {
            if ($v->getEnabled()) {
                $hidden = array('fedid' => $federation->getId(), 'provid' => $provider->getId(), 'fvid' => $v->getId());

                if ($v->getMandatory()) {
                    $valMandatory .= form_open(base_url() . 'federations/fvalidator/validate', $attrs, $hidden) .
                        '<button class="button" id="' . $v->getId() . '" title="' . $v->getDescription() . '" name="mandatory">' . $v->getName() . '</button> ' . form_close();
                } else {
                    $valOptional .= form_open(base_url() . 'federations/fvalidator/validate', $attrs, $hidden) .
                        '<button class="button" id="' . $v->getId() . '" title="' . $v->getDescription() . '">' . $v->getName() . '</button> ' . form_close();
                }

            }
        }
        $data = $queue->getData();
        array_push($rows,
            array(lang('manValidator'), $valMandatory),
            array(lang('optValidator'), $valOptional),
            array(lang('rr_federation'), $federation->getName() . ' '),
            array(lang('rr_provider'), $data['name']),
            array(lang('rr_entityid'), $data['entityid']),
            array('Provider status', '<div  data-jagger-getmoreajax= "' . base_url() . 'providers/detail/status/' . $data['id'] . '" data-jagger-response-msg="providerstatus"></div><div id="providerstatus" data-alert class="alert-box info">' . lang('rr_noentitywarnings') . '</div>'),
            array(lang('request'), lang('acceptprovtofed'))
        );

        if (isset($data['message'])) {
            $rows[] = array(lang('rr_message'), $data['message']);
        }
        $rows[] = array('data' => $this->displayFormsButtons($queue->getID(), !$canApprove), 'colspan' => 2);


        # show additional information returned by validator
        $text = '<div id="fvresult" style="display:none;" data-alert class="alert-box info"><div><b>' . lang('fvalidcodereceived') . '</b>: <span id="fvreturncode"></span></div><div><p><b>' . lang('fvalidmsgsreceived') . '</b>:</p><div id="fvmessages"></div></div></div><div id="fvalidesc"></div>';

        $rows[] = array('data' => $text, 'colspan' => 2);
        foreach ($rows as $row) {
            $this->ci->table->add_row($row);
        }
        $result = $this->ci->table->generate();
        $this->ci->table->clear();

        return $result;
    }

    public function detailFederation(models\Queue $qObject) {
        $objAction = $qObject->getAction();
        $recipientType = $qObject->getRecipientType();
        if (strcasecmp($objAction, 'Create') == 0) {
            $fedrows = $this->displayRegisterFederation($qObject);
            $fedrows[]['2cols'] = $this->displayFormsButtons($qObject->getId());
            $data['fedrows'] = $fedrows;
            $data['content_view'] = 'reports/awaiting_federation_register_view';
            $r['data'] = $data;

            return $r;
        }
        if (strcasecmp($objAction, 'Join') == 0 && strcasecmp($recipientType, 'provider') == 0) {
            $recipient_write_access = $this->ci->zacl->check_acl($qObject->getRecipient(), 'write', 'entity', '');
            $requestor_view_access = (strcasecmp($qObject->getCreator()->getUsername(), $this->ci->jauth->getLoggedinUsername()) == 0);
            if ($requestor_view_access || $recipient_write_access) {
                $result = $this->displayInviteProvider($qObject);
                if (!empty($result)) {
                    $data['result'] = $result;
                } else {
                    $data['error_message'] = "Couldn't load request details";
                }
            } else {
                $data['error_message'] = lang('rerror_noperm_viewqueuerequest');
            }

            $data['content_view'] = 'reports/awaiting_invite_provider_view';
            $r['data'] = $data;

            return $r;
        }
        if (strcasecmp($objAction, 'Delete') == 0) {
            $fedrows = $this->displayDeleteFederation($qObject);
            $fedrows[]['2cols'] = $this->displayFormsButtons($qObject->getId(), !$this->ci->jauth->isAdministrator());
            $data['fedrows'] = $fedrows;
            $data['content_view'] = 'reports/awaiting_federation_register_view';
            $r['data'] = $data;

            return $r;
        }

        return null;
    }

}

