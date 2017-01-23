<?php
/**
 * maintenanceMode : Put installation on Maintenance mode
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2017 Denis Chenu <http://www.sondages.pro>

 * @license AGPL v3
 * @version 0.0.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class maintenanceMode extends \ls\pluginmanager\PluginBase {
    /* DbStorage for date of maintenance and redirect url */
    protected $storage = 'DbStorage';

    static protected $description = 'Put on maintenance mode , option to redirect to specific page.';
    static protected $name = 'maintenanceMode';

    protected $settings = array(
        'dateTime' => array(
            'type' => 'date',
            'label' => 'Date / time for maintenance mode.',
            'help' => 'Leave empty disable maintenance mode.',
            'default'=> '',
        ),
        'superAdminOnly' => array(
            'type'=>'boolean',
            'label'=>'Allow only super administrator.',
            'help'=>'Remind : admin login page are always accessible.',
            'default'=>0,
        ),
        'disablePublicPart' => array(
            'type'=>'boolean',
            'label'=>'Disable public part even if admin.',
            'default'=>0,
        ),
        'urlRedirect' => array(
            'type'=>'string',
            'label'=>'Url to redirect users',
            'help'=>'Use {LANGUAGE} allow to use user language (ISO format if exist in this installation). Enter complete url only (with http:// or https://)',
            'default'=>'',
        ),
        'messageToShow' => array(
            'type'=>'text',
            'label'=>'Message to be shown',
            'htmlOptions'=>array(
                'placeholder'=>'This website are in instance mode.',
            ),
            'help'=>'Not needed if you use redirect',
            'default'=>'',
        ),
        'disableMailSend' => array(
            'type'=>'boolean',
            'label'=>'Disable token emailing during maintenance',
            'default'=>1,
        ),
    );
    public function init()
    {
        $this->subscribe('beforeControllerAction');
        $this->subscribe('beforeTokenEmail');
        $this->subscribe('beforeActivate');
    }

    /**
     * Activate or not
     */
    public function beforeActivate()
    {
        $oToolsSmartDomDocument = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        if(!$oToolsSmartDomDocument)
        {
            $this->getEvent()->set('message', gT("You must download renderMessage plugin"));
            $this->getEvent()->set('success', false);
        }
        elseif(!$oToolsSmartDomDocument->active)
        {
            $this->getEvent()->set('message', gT("You must activate renderMessage plugin"));
            $this->getEvent()->set('success', false);
        }
    }

    /*
     * If not admin part : redirect to specfic page or show a message
     */
    public function beforeControllerAction()
    {
        /* no maintenance mode for command */
        if(Yii::app() instanceof CConsoleApplication) {
            return;
        }
        /* Get actual time */
        //~ tracevar([$this->_inMaintenance(),$this->_accessAllowed()]);
        if($this->_inMaintenance() && !$this->_accessAllowed()){
            $url=$this->get('urlRedirect');
            if($url){
                $lang=Yii::app()->request->getParam('lang',Yii::app()->request->getParam('language'));
                if(!$lang){
                    $lang=Yii::app()->getConfig('defaultlang');
                }
                $url=str_replace("{LANGUAGE}",$lang,$url);
                header('Location: '.$url);
            }
            $message=$this->get('messageToShow',null,null,$this->settings['messageToShow']['htmlOptions']['placeholder']);
            $renderMessage = new \renderMessage\messageHelper();
            $renderMessage->render("<div class='alert alert-warning'>{$message}</div>");
        }
    }
    /**
     * Disable sending of email
     */
    public function beforeTokenEmail()
    {

        if($this->_inMaintenance()){
            if($this->get('disableMailSend',null,null,$this->settings['disableMailSend']['default'])){
                $this->event->set('send',false);
                $this->event->set('error','Maintenance mode.');
            }
        }
    }
    /**
     * @see ls\pluginmanager\PluginBase
     * @param type $settings
     */
    public function saveSettings($settings)
    {
        if(!empty($settings['dateTime'])){
            $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
            $oDateTimeConverter = new Date_Time_Converter($settings['dateTime'], $aDateFormatData['phpdate'] . " H:i");
            $settings['dateTime']=$oDateTimeConverter->convert("Y-m-d H:i");
        }
        if(!empty($settings['urlRedirect'])){
            if(!filter_var($settings['urlRedirect'],FILTER_VALIDATE_URL)){
                $settings['urlRedirect']="";
                Yii::app()->setFlashMessage(gT("Bad url, you must review the redirect url."),'error');
            }
        }
        parent::saveSettings($settings);
    }
    /**
     *  @see ls\pluginmanager\PluginBase
     */
    public function getPluginSettings($getValues=true)
    {
        $pluginSettings= parent::getPluginSettings($getValues);
        if($getValues){
            if(!empty($pluginSettings['dateTime']['current'])){
                $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
                $oDateTimeConverter = new Date_Time_Converter($pluginSettings['dateTime']['current'], "Y-m-d H:i");
                $pluginSettings['dateTime']['current']=$oDateTimeConverter->convert($aDateFormatData['phpdate']." H:i");
            }
        }
        //~ $pluginSettings['messageToShow']['htmlOptions']['placeholder']=gT("This website are on maintenance mode");
        return $pluginSettings;
    }

    /**
     * Return if website are in maintenance mode
     * @return boolean
     */
    private function _inMaintenance(){
        if($this->get('dateTime')) {
            $maintenanceDateTime=$this->get('dateTime').":00";
            $maintenanceDateTime=dateShift($maintenanceDateTime, "Y-m-d H:i:s",Yii::app()->getConfig("timeadjust"));
            if(strtotime($maintenanceDateTime) < strtotime("now")){
                return true;
            }
        }
        return false;
    }

    /**
     * Allow access
     */
    private function _accessAllowed(){
        /* A1llow admin in condition */
        if(Yii::app()->session['loginID'] && !$this->get('superAdminOnly')){
            return true;
        }
        /* Always allow admin login */
        if(!Yii::app()->session['loginID'] && $this->event->get('controller')=='admin'){
            return true;
        }
        /* Always allow superadmin */
        if(Permission::model()->hasGlobalPermission("superadmin") && $this->event->get('controller')=='admin'){
            return true;
        }
        if(Yii::app()->session['loginID'] && !$this->event->get('controller')=='admin' && $this->get('disablePublicPart')){
            return true;
        }
        return false;
    }
}
