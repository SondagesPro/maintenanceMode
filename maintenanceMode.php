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
            'help' => 'Empty disable maintenance mode.',
            'default'=> '',
        ),
        'timeForDelay' => array(
            'type' => 'string',
            'label' => 'Delay for warning.',
            'help' => 'In minutes',//@todo : relative format : ' or using <a href="//php.net/manual/datetime.formats.relative.php">Relative Formats</a>.',
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
                'placeholder'=>'This website are on maintenance mode.',
            ),
            'help'=>'Not needed if you use redirect',
            'default'=>'',
        ),
        'warningToShow' => array(
            'type' => 'text',
            'label' => 'Warning message.',
            'htmlOptions'=>array(
                'placeholder'=>'<strong>Warning</strong> This website close for maintenance at {DATEFORMATTED} (in {MINUTES} minutes).',
            ),
            'help' => '{DATEFORMATTED} was replaced by date (in user language format) and minutes by number of minutes before maintenance.',//@todo : allow EM with DATE/DATEFORMATTED AND MINUTES
            'default'=> '',
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

        /* To add own translation message source */
        $this->subscribe('afterPluginLoad');
    }

    /**
     * Activate or not
     */
    public function beforeActivate()
    {
        $oToolsSmartDomDocument = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        if(!$oToolsSmartDomDocument)
        {
            $this->getEvent()->set('message', $this->_translate("You must download renderMessage plugin"));
            $this->getEvent()->set('success', false);
        }
        elseif(!$oToolsSmartDomDocument->active)
        {
            $this->getEvent()->set('message', $this->_translate("You must activate renderMessage plugin"));
            $this->getEvent()->set('success', false);
        }
    }

    /*
     * If not admin part : redirect to specific page or show a message
     */
    public function beforeControllerAction()
    {
        /* no maintenance mode for command */
        if(Yii::app() instanceof CConsoleApplication) {
            return;
        }
        /* Get actual time */
        if($this->_inMaintenance()){
            if($this->_accessAllowed()){
                $renderFlashMessage = \renderMessage\flashMessageHelper::getInstance();
                $renderFlashMessage->addFlashMessage($this->_translate("This website are on maintenance mode."));
                return;
            }
            $this->_endDuToMaintenance();
        }elseif(!is_null($this->_inWarningMaintenance())){
            $this->_warningDuToMaintenance();
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
        if(!empty($settings['timeForDelay'])){
            $settings['timeForDelay']=filter_var($settings['timeForDelay'],FILTER_SANITIZE_NUMBER_INT);
        }
        if(!empty($settings['urlRedirect'])){
            if(!filter_var($settings['urlRedirect'],FILTER_VALIDATE_URL)){
                $settings['urlRedirect']="";
                Yii::app()->setFlashMessage($this->_translate("Bad url, you must review the redirect url."),'error');
            }
        }
        parent::saveSettings($settings);
    }
    /**
     * fix some settings
     * @see ls\pluginmanager\PluginBase
     */
    public function getPluginSettings($getValues=true)
    {
        $pluginSettings= parent::getPluginSettings($getValues);
        if($getValues){
            if(!empty($pluginSettings['dateTime']['current'])){
                /* renderDate broken in LS core */
                $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
                $oDateTimeConverter = new Date_Time_Converter($pluginSettings['dateTime']['current'], "Y-m-d H:i");
                $pluginSettings['dateTime']['current']=$oDateTimeConverter->convert($aDateFormatData['phpdate']." H:i");
            }
        }
        $pluginSettings['messageToShow']['htmlOptions']['placeholder']=$this->_translate("This website are on maintenance mode.");
        $pluginSettings['warningToShow']['htmlOptions']['placeholder']=sprintf("<strong class='h4'>%s</strong><p>%s</p>",$this->_translate("Warning"),$this->_translate("This website close for maintenance at {DATEFORMATTED} (in {intval(MINUTES)} minutes)."));
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
     * Return if website warning a maintenance mode
     * @return null|float (number of minutes)
     */
    private function _inWarningMaintenance(){
        if($this->get('dateTime') && $this->get('timeForDelay')) {
            $timeFoDelay=$this->get('timeForDelay');
            $timeFoDelay=((is_numeric($timeFoDelay)) ? "-".$timeFoDelay." minutes" : $timeFoDelay);
            $maintenanceDateTime=$this->get('dateTime').":00";
            $maintenanceWarningTime=strtotime("{$maintenanceDateTime} {$timeFoDelay}");
            if($maintenanceWarningTime < strtotime("now")){
                return (strtotime($maintenanceDateTime)-strtotime("now"))/60;
            }
        }
        return false;
    }
    /**
     * Allow access
     * @return boolean
     */
    private function _accessAllowed(){
        /* don't used : Yii::app()->user->isGuest : reset the App()->language */
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

    /**
     * ending page due to maintenance
     * @return void (and end)
     */
    private function _endDuToMaintenance(){
        $url=$this->get('urlRedirect');
        if($url){
            //$lang=App()->language; // don't use it : must control if is in available language
            $lang=Yii::app()->request->getParam('lang',Yii::app()->request->getParam('language'));
            if(!$lang){
                $lang=Yii::app()->getConfig('defaultlang');
            }

            $url=str_replace("{LANGUAGE}",$lang,$url);
            header('Location: '.$url);
        }
        $message=$this->get('messageToShow',null,null,$this->_translate("This website are on maintenance mode."));
        if(!$message){
            $message=$this->_translate("This website are on maintenance mode.");
        }
        $renderMessage = new \renderMessage\messageHelper();
        $renderMessage->render("<div class='alert alert-warning'>{$message}</div>");
        /* rendering quit */
    }

    private function _warningDuToMaintenance(){
        $message=$this->get('warningToShow');
        if(!$message){
            $message=sprintf("<strong class='h4'>%s</strong><p>%s</p>",$this->_translate("Warning"),$this->_translate("This website close for maintenance at {DATEFORMATTED} (in {intval(MINUTES)} minutes)."));
        }
        $date=$this->get('dateTime').":00";
        $aLanguage=getLanguageDetails(Yii::app()->language);
        $oDateTimeConverter = new Date_Time_Converter($date, "Y-m-d H:i");
        $aDateFormat=getDateFormatData($aLanguage['dateformat']);
        $dateFormatted=$oDateTimeConverter->convert($aDateFormat['phpdate']." H:i");
        $minutesBeforeMaintenance=$this->_inWarningMaintenance();
        $aReplacement=array(
            'DATE'=>$date,
            'DATEFORMATTED'=>$dateFormatted,
            'MINUTES'=>$minutesBeforeMaintenance,
        );
        $message=LimeExpressionManager::ProcessString($message, null, $aReplacement, false, 2, 1, false, false,true);
        $renderFlashMessage = \renderMessage\flashMessageHelper::getInstance();
        $timeFoDelay=$this->get('timeForDelay');
        $class=($minutesBeforeMaintenance < ($timeFoDelay/10)) ? 'danger' : 'warning';
        $renderFlashMessage->addFlashMessage($message,$class);
    }
    private function _translate($string){
        return Yii::t('',$string,array(),'maintenanceMode');
    }
    /**
     * Add this translation just after loaded all plugins
     * @see event afterPluginLoad
     */
    public function afterPluginLoad(){
        // messageSource for this plugin:
        $messageMaintenanceMode=array(
            'class' => 'CGettextMessageSource',
            'cacheID' => 'maintenanceModeLang',
            'cachingDuration'=>3600,
            'forceTranslation' => true,
            'useMoFile' => true,
            'basePath' => __DIR__ . DIRECTORY_SEPARATOR.'locale',
            'catalog'=>'messages',// default from Yii
        );
        Yii::app()->setComponent('maintenanceMode',$messageMaintenanceMode);
    }
}
