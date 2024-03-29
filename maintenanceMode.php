<?php
/**
 * maintenanceMode : Put installation on Maintenance mode
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2017-2023 Denis Chenu <http://www.sondages.pro>

 * @license AGPL v3
 * @version 1.6.0
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
class maintenanceMode extends PluginBase {
    /* DbStorage for date of maintenance and redirect url */
    protected $storage = 'DbStorage';

    static protected $description = 'Put on maintenance mode , option to redirect to specific page.';
    static protected $name = 'maintenanceMode';

    protected $settings = array(
        'dateTime' => array(
            'type' => 'date',
            'default'=> '',
        ),
        'timeForDelay' => array(
            'type' => 'string',
            'default'=> '60',
        ),
        'superAdminOnly' => array(
            'type'=>'boolean',
            'default'=>0,
        ),
        'disablePublicPart' => array(
            'type'=>'boolean',
            'default'=>0,
        ),
        'messageToShow' => array(
            'type'=>'text',
            'default'=>'',
        ),
        'warningToShow' => array(
            'type' => 'text',
            'default'=> '',
        ),
        'disableMailSend' => array(
            'type'=>'boolean',
            'default'=>1,
        ),
        'urlRedirect' => array(
            'type'=>'string',
            'default'=>'',
        ),
    );
    public function init()
    {
        if(intval(App()->getConfig('versionnumber')) < 3) {
            return;
        }
        $this->subscribe('beforeActivate');
        $oRenderMessage = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        if(!$oRenderMessage || !$oRenderMessage->active) {
            $this->subscribe('beforeControllerAction','addFlashMessage');
            return;
        }

        $this->subscribe('beforeControllerAction');
        /* disable login for admin */
        $this->subscribe('newUserSession');
        /* Disable mail send */
        $this->subscribe('beforeTokenEmail');
        /* This need twigExtendByPlugins */
        $this->subscribe('getPluginTwigPath');
    
    }

    public function newUserSession() {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        if($this->get('superAdminOnly') && $this->_inMaintenance()){
            $identity = $this->getEvent()->get('identity');
            $sUser  = $identity->username;
            $oUser = $this->api->getUserByName($sUser);
            if($oUser) {
                if(!Permission::model()->hasGlobalPermission('superadmin','read',$oUser->uid)) {
                    $identity->id = null;
                    $this->getEvent()->set('result', new LSAuthResult(99, $this->gT("This website is in maintenance mode.")));
                    $this->getEvent()->stop();
                }
            }
        }
    }

    /* @see plugin event */
    public function getPluginTwigPath() 
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        $viewPath = dirname(__FILE__)."/views";
        $this->getEvent()->append('add', array($viewPath));
    }

    /**
     * See plugin event
     * Add flash message for admin
     */
    public function afterPluginLoad() {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        if(Yii::app()->getController()->getId() == "admin" && Permission::model()->hasGlobalPermission("settings","update") ) {
            $oRenderMessage = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
            if(!$oRenderMessage) {
                App()->setFlashMessage($this->gT("You must download and activate renderMessage plugin"),'error');
                return;
            } elseif(!$oRenderMessage->active) {
                 App()->setFlashMessage($this->gT("You must activate renderMessage plugin"),'error');
                return;
            }
        }
    }
    /**
     * fix some settings
     * @see PluginBase
     */
    public function getPluginSettings($getValues=true)
    {
        if(!Permission::model()->hasGlobalPermission('settings','read')) {
            throw new CHttpException(403);
        }
        $pluginSettings = parent::getPluginSettings($getValues);
        /* Add a big warning about needed renderMessage */
        $oRenderMessage = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        if(!$oRenderMessage) {
            $warningRenderMessage['warningRenderMessage']= array(
                'type'=>"info",
                'content' => CHtml::tag("p",array(),$this->gT("You can not use maintenance mode with actual configuration."))
                    . CHtml::tag("p",array(),$this->gT("You must download and activate renderMessage plugin")),
                'class'=>"h3 alert alert-warning",
            );
            $pluginSettings = $warningRenderMessage + $pluginSettings;
        } elseif(!$oRenderMessage->active) {
            $warningRenderMessage['warningRenderMessage']= array(
                'type'=>"info",
                'content' => CHtml::tag("p",array(),$this->gT("You can not use maintenance mode with actual configuration."))
                    . CHtml::tag("p",array(),$this->gT("You must and activate renderMessage plugin")),
                'class'=>"h3 alert alert-warning",
            );
            $pluginSettings = $warningRenderMessage + $pluginSettings;
        }
        $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
        if($getValues){
            if(!empty($pluginSettings['dateTime']['current'])){
                /* renderDate broken in LS core */
                $oDateTimeConverter = new Date_Time_Converter($pluginSettings['dateTime']['current'], "Y-m-d H:i");
                $pluginSettings['dateTime']['current']=$oDateTimeConverter->convert($aDateFormatData['phpdate']." H:i");
            }
        }
        /* Move settings partiallyfro translation */
        $translatedSettings = array(
            'dateTime' => array(
                'label' => $this->gT("Date / time for maintenance mode."),
            ),
            'timeForDelay' => array(
                'label' => $this->gT("Show warning message delay."),
                'help' => $this->gT("In minutes or with english date string. With usage of minutes, message is set to danger when there are only 10% of the time for maintenance."),
            ),
            'superAdminOnly' => array(
                'label'=> $this->gT("Allow only super administrator to admin page."),
                'help'=> $this->gT("Admin login page are always accessible."),
            ),
            'disablePublicPart' => array(
                'label'=> $this->gT("Disable public part for administrator users."),
                'help'=> $this->gT("Even for Superadministrator(s)."),
            ),
            'messageToShow' => array(
                'label'=> $this->gT("Maintenance message"),
                'htmlOptions'=>array(
                    'placeholder'=> $this->gT("This website is in maintenance mode."),
                ),
                'help' => $this->gT("Default message was translated according to user language."),
            ),
            'warningToShow' => array(
                'label' =>  $this->gT("Warning message."),
                'htmlOptions'=>array(
                    'placeholder'=>sprintf("<strong class='h4'>%s</strong><p>%s</p>",$this->gT("Warning"),$this->gT("This website will close for maintenance at {DATEFORMATTED} (in {intval(MINUTES)} minutes).")),
                ),
                'help' => sprintf(
                    $this->gT("You can use Expression manager : %s was replaced by date in user language format, %s by date in %s format and %s by number of minutes before maintenance."),
                    "{DATEFORMATTED}","{DATE}","<code>Y-m-d H:i</code>","{MINUTES}"
                ),
            ),
            'disableMailSend' => array(
                'label'=>$this->gT("Disable token emailing during maintenance"),
                'default'=>1,
            ),
            'urlRedirect' => array(
                'label'=>$this->gT("Url to redirect users"),
                'help'=>$this->gT("Enter complete url only (with http:// or https://). {LANGUAGE} was replace by user language (ISO format if exist in this installation)."),
            ),
        );

        $pluginSettings=array_merge_recursive($pluginSettings,$translatedSettings);
        /* Help of maintenance : add actual date */
        $dateTimeNow=dateShift(date('Y-m-d H:i:s'), "Y-m-d H:i:s",Yii::app()->getConfig("timeadjust"));
        $oDateTimeConverter = new Date_Time_Converter($dateTimeNow, "Y-m-d H:i");
        $dateTimeNow=$oDateTimeConverter->convert($aDateFormatData['phpdate']." H:i");
        $pluginSettings['dateTime']['help']=sprintf($this->gT("Actual date/time : %s. Empty disable maintenance mode."),$dateTimeNow);

        /* Help on admin, but not super admin */
        if(!Permission::model()->hasGlobalPermission("superadmin")){
            $pluginSettings['superAdminOnly']['help']=sprintf("<div class='text-danger'> %s </div>",$this->gT("This disable your access"));
        }
        return $pluginSettings;
    }

    /**
     * Activate or not
     */
    public function beforeActivate()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        // Control LimeSurvey version
        $lsVersion = floatval(Yii::app()->getConfig('versionnumber'));
        if($lsVersion < 3) {
            $this->getEvent()->set('message', gT("Only for LimeSurvey 3.0.0 and up version"));
            $this->getEvent()->set('success', false);
            return;
        }
        if($lsVersion >= 4) {// See https://github.com/LimeSurvey/LimeSurvey/pull/1078
            return;
        }
        $oRenderMessage = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        if(!$oRenderMessage) {
            $this->getEvent()->set('message', gT("You must download renderMessage plugin"));
            $this->getEvent()->set('success', false);
        } elseif(!$oRenderMessage->active) {
            $this->getEvent()->set('message', gT("You must activate renderMessage plugin"));
            $this->getEvent()->set('success', false);
        }

    }

    /**
     * If admin part : add the warning message for admin user with settings update allaowed
     */
    public function addFlashMessage()
    {
        if(Yii::app()->getRequest()->getIsAjaxRequest()) {
            /* Don't add multiple times, this disable it in soma page, but just need to see it one time */
            return;
        }
        $addFlash = $this->getEvent()->get("controller")== "admin" && Permission::model()->hasGlobalPermission("settings","update");
        $oRenderMessage = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        if(!$oRenderMessage) {
            $this->log("You must download and activate renderMessage plugin",'error');
            if($addFlash) {
                App()->setFlashMessage($this->gT("You have maintenanceMode plugin activated : it requires the plugin renderMessage."),"error");
            }
            return;
        }
        if(!$oRenderMessage->active) {
            $this->log("You must activate renderMessage plugin",'error');
            if($addFlash) {
                App()->setFlashMessage($this->gT("You have maintenanceMode plugin activated : it requires the plugin renderMessage activated."),"error");
            }
        }
    }
    /*
     * If not admin part : redirect to specific page or show a message
     */
    public function beforeControllerAction()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        /* Don't add it 2 times, strangely beforeControllerAction happen 2 times */
        static $done;
        
        /* no maintenance mode for command */
        if(Yii::app() instanceof CConsoleApplication) {
            return;
        }
        if($done) {
            return;
        }
        /* Do action only one time : beforeControllerAction happen 2 times , @todo report issue */
        $this->unsubscribe('beforeControllerAction');
        $done = true;
        if($this->_inMaintenance()){
            if($this->_accessAllowed()){
                $this->_addFlashMessage($this->gT("This website is in maintenance mode."));
                return;
            }
            $this->_endDuToMaintenance();
            App()->end(); // Not needed , but more clear
        }
        if(!is_null($this->_inWarningMaintenance())){
            $this->_warningDuToMaintenance();
        }
    }
    /**
     * Disable sending of email
     */
    public function beforeTokenEmail()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
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
        if(!Permission::model()->hasGlobalPermission('settings','update')) {
            throw new CHttpException(403);
        }
        if(!empty($settings['dateTime'])){
            $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
            $oDateTimeConverter = new Date_Time_Converter($settings['dateTime'], $aDateFormatData['phpdate'] . " H:i");
            $settings['dateTime']=$oDateTimeConverter->convert("Y-m-d H:i");
        }
        if(!empty($settings['timeForDelay'])){
            if(filter_var($settings['timeForDelay'],FILTER_SANITIZE_NUMBER_INT)) {
                $settings['timeForDelay']=$settings['timeForDelay'];
                $settings['timeForDelayInMinute']= 1;
            } elseif(date($settings['timeForDelay'])) {
                $settings['timeForDelay']=$settings['timeForDelay'];
                $settings['timeForDelayInMinute']= 0;
            } else {
                Yii::app()->setFlashMessage($this->gT("Bad delay, you must review the time for delay."),'error');
                $settings['timeForDelay']="";
            }
        }
        if(!empty($settings['urlRedirect'])){
            if(!filter_var($settings['urlRedirect'],FILTER_VALIDATE_URL)){
                $settings['urlRedirect']="";
                Yii::app()->setFlashMessage($this->gT("Bad url, you must review the redirect url."),'error');
            }
        }
        if(!empty($settings['warningToShow'])) {
            $oPurifier = new CHtmlPurifier();
            $settings['warningToShow'] = $oPurifier->purify($settings['warningToShow']);
        }
        if(!empty($settings['messageToShow'])) {
            $oPurifier = new CHtmlPurifier();
            $settings['messageToShow'] = $oPurifier->purify($settings['messageToShow']);
        }
        parent::saveSettings($settings);
    }

    /**
     * @inheritdoc
     */
    public static function getDescription()
    {
        $oRenderMessage = Plugin::model()->find("name=:name",array(":name"=>'renderMessage'));
        
        if(!$oRenderMessage) {
            return "You must download and activate renderMessage plugin to use maintenanceMode.";
        }
        if(!$oRenderMessage->active) {
            return "You must activate renderMessage plugin to use maintenanceMode.";
        }
        // Unable to use $this since we are in a static function.
        return "Put your LimeSurvey instance on maintenance mode , allow admin access or not.";
    }

    /**
     * Return if website are in maintenance mode
     * @return boolean
     */
    private function _inMaintenance(){
        if($this->get('dateTime')) {
            $maintenanceDateTime=$this->get('dateTime').":00";
            $dateTimeNow=dateShift(date('Y-m-d H:i:s'), "Y-m-d H:i:s",Yii::app()->getConfig("timeadjust"));
            $this->log("maintenanceDateTime : $maintenanceDateTime");
            if(strtotime($maintenanceDateTime) < strtotime($dateTimeNow)){
                $this->log("In maintenance");
                return true;
            }
            $this->log("Not in maintenance");
        }
        return false;
    }
    /**
     * Return if website warning a maintenance mode
     * @return null|float (number of minutes)
     */
    private function _inWarningMaintenance(){
        if(trim($this->get('dateTime')) && trim($this->get('timeForDelay',null,null,$this->settings['timeForDelay']['default']))) {
            $maintenanceDateTime=$this->get('dateTime').":00";
            $dateTimeNow=dateShift(date('Y-m-d H:i:s'), "Y-m-d H:i:s",Yii::app()->getConfig("timeadjust"));
            $timeFoDelay=$this->get('timeForDelay',null,null,$this->settings['timeForDelay']['default']);
            if(is_numeric($timeFoDelay) || strval(intval($timeFoDelay)) == strval($timeFoDelay) ) {
                $maintenanceWarningTime=strtotime($maintenanceDateTime) - $timeFoDelay*60;
            } else {
                $maintenanceWarningTime=strtotime($timeFoDelay);
            }
            if($maintenanceWarningTime < strtotime($dateTimeNow)){
                return (strtotime($maintenanceDateTime) - strtotime($dateTimeNow))/60;
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
        $isAdminController = self::isAdminController();
        /* Always allow superadmin */
        if (Permission::model()->hasGlobalPermission("superadmin") && $isAdminController) {
            return true;
        }
        /* Allow pluginmanager access for user with settings update */
        if(Permission::model()->hasGlobalPermission("settings",'update') && $isAdminController) {
            return true;
        }
        
        /* Always allow admin login */
        if(!Yii::app()->session['loginID'] && $isAdminController){
            return true;
        }
        /* Allow admin in condition : disablePublicPart is true (and not admin)*/
        if(Yii::app()->session['loginID'] && !$isAdminController && !$this->get('disablePublicPart')){
            return true;
        }
        /* Allow admin in condition : superAdminOnly is false*/
        if(Yii::app()->session['loginID'] && $isAdminController && !$this->get('superAdminOnly')){
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
        $message = $this->get('messageToShow',null,null,$this->gT("This website is in maintenance mode."));
        if(!$message){
            $message = $this->gT("This website is in maintenance mode.");
        }
        /* rendering quit */
        //$this->_render($message);
        \renderMessage\messageHelper::renderAlert($message,'warning');
    }

    private function _warningDuToMaintenance(){
        $minutesBeforeMaintenance=$this->_inWarningMaintenance();
        if($minutesBeforeMaintenance){
            $message=$this->get('warningToShow');
            if(empty($message)){
                $message=sprintf("<strong class='h4'>%s</strong><p>%s</p>",$this->gT("Warning"),$this->gT("This website will close for maintenance at {DATEFORMATTED} (in {intval(MINUTES)} minutes)."));
            }
            $maintenanceDateTime=$this->get('dateTime').":00";
            $aLanguage=getLanguageDetails(Yii::app()->language);
            $oDateTimeConverter = new Date_Time_Converter($maintenanceDateTime, "Y-m-d H:i");
            $aDateFormat=getDateFormatData($aLanguage['dateformat']);
            $dateFormatted=$oDateTimeConverter->convert($aDateFormat['phpdate']." H:i");
            $aReplacement=array(
                'DATE'=>$maintenanceDateTime,
                'DATEFORMATTED'=>$dateFormatted,
                'MINUTES'=>$minutesBeforeMaintenance,
            );
            $message=LimeExpressionManager::ProcessStepString($message, $aReplacement, 3,true);
            $timeFoDelay=$this->get('timeForDelay');
            $class = 'warning';
            if(is_numeric($timeFoDelay) && ($minutesBeforeMaintenance < ($timeFoDelay/10)) ) {
                $class = 'danger';
            }
            $this->_addFlashMessage($message,$class);
        }
    }

    /**
     * @inheritdoc
     * With default escape mode to 'unescaped'
     */
    public function gT($sToTranslate, $sEscapeMode = 'unescaped', $sLanguage = null)
    {
        return parent::gT($sToTranslate, $sEscapeMode, $sLanguage);
    }

    /**
     * @inheritdoc
     * Adding message to vardump if user activate debug mode
     * Use default plugin log too
     */
    public function log($message, $level = \CLogger::LEVEL_TRACE)
    {
        if(is_callable("parent::log")) {
            parent::log($message, $level);
        }
        Yii::log("[".get_class($this)."] ".$message, $level, 'vardump');
    }
    
    /**
     * Add a flash message to user
     * @todo
     * @return void
     */
    private function _addFlashMessage($message,$class='warning')
    {
        if(Yii::app()->getRequest()->isAjaxRequest) {
            return;
        }
        if (self::isAdminController()) {
            Yii::app()->setFlashMessage($message, $class);
            return;
        }
        \renderMessage\messageHelper::addFlashMessage($message,$class);
    }

    /**
     * Check if current controller is an admin controller
     * @return boolena
     */
    private static function isAdminController()
    {
        $controller = Yii::app()->getController()->getId();
        if($controller=='admin') {
            return true;
        }
        /* 5X controller */
        $adminControllers = [
            'surveyadministration',
            'assessment',
            'failedemail',
            'questionadministration',
            'questiongroupsadministration',
            'surveypermissions',
            'surveysgroupspermission',
            'themeoptions',
            'usergroup',
            'usermanagement',
            'userrole',
        ];
        if (in_array(strtolower($controller) , $adminControllers)) {
            return true;
        }
        return false;
    }
}
