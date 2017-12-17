<?php

class LockMe_Installer{
  
  static public function check(){
    register_activation_hook(LOCKME_PLUGIN_FILE, array('LockMe_Installer', 'install'));
    register_uninstall_hook(LOCKME_PLUGIN_FILE, array('LockMe_Installer', 'uninstall'));
  }
  
  public function install(){
  }
  
  public function uninstall(){
  }
}
