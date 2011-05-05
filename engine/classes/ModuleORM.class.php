<?php
/*-------------------------------------------------------
*
*   LiveStreet Engine Social Networking
*   Copyright © 2008 Mzhelskiy Maxim
*
*--------------------------------------------------------
*
*   Official site: www.livestreet.ru
*   Contact e-mail: rus.engine@gmail.com
*
*   GNU General Public License, version 2:
*   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*
---------------------------------------------------------
*/

/**
 * Абстракция модуля ORM
 *
 */
abstract class ModuleORM extends Module {
	
	protected $oMapperORM=null;
	
	public function Init() {
		$this->_LoadMapperORM();
	}
	
	protected function _LoadMapperORM() {
		$this->oMapperORM=new MapperORM($this->oEngine->Database_GetConnect());
	}
	
	
	protected function _AddEntity($oEntity) {
		$res=$this->oMapperORM->AddEntity($oEntity);
		// сбрасываем кеш
		if ($res===0 or $res) {
			$sEntity=$this->Plugin_GetRootDelegater('entity',get_class($oEntity));
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array($sEntity.'_save'));
		}
		if ($res===0) {
			// у таблицы нет автоинремента
			return $oEntity;
		} elseif ($res) {
			// есть автоинкремент, устанавливаем его
			$oEntity->_setData(array($oEntity->_getPrimaryKey() => $res));
            // Обновление связей many_to_many
            $aRelationsData = $oEntity->_getRelationsData();
            foreach ($oEntity->_getRelations() as $sRelName => $aRelation) {
                if ($aRelation[0] == EntityORM::RELATION_TYPE_MANY_TO_MANY && isset($aRelationsData[$sRelName])) {
                    // Сброс кэша по связям
                    $this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('m2m_'.$aRelation[2].$aRelation[4].$oEntity->_getPrimaryKeyValue()));
                    
                    $this->_updateManyToManySet($aRelation, $aRelationsData[$sRelName], $oEntity->_getDataOne($oEntity->_getPrimaryKey()));
                }
            }
			return $oEntity;
		}
		return false;
	}
	
	protected function _UpdateEntity($oEntity) {
		$res=$this->oMapperORM->UpdateEntity($oEntity);

		if ($res===0 or $res) { // запись не изменилась, либо изменилась
            // Обновление связей many_to_many
            $aRelationsData = $oEntity->_getRelationsData();
            foreach ($oEntity->_getRelations() as $sRelName => $aRelation) {
                if ($aRelation[0] == EntityORM::RELATION_TYPE_MANY_TO_MANY) {
                    // Сброс кэша по связям
                    $this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('m2m_'.$aRelation[2].$aRelation[4].$oEntity->_getPrimaryKeyValue()));
                    $this->_updateManyToManySet($aRelation, $aRelationsData[$sRelName], $oEntity->_getDataOne($oEntity->_getPrimaryKey()));
                }
            }
			// сбрасываем кеш
			$sEntity=$this->Plugin_GetRootDelegater('entity',get_class($oEntity));
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array($sEntity.'_save'));
			return $oEntity;
		}		
		return false;
	}

	protected function _SaveEntity($oEntity) {
		if ($oEntity->_isNew()) {
			return $this->_AddEntity($oEntity);
		} else {
			return $this->_UpdateEntity($oEntity);
		}
	}	
	
	protected function _DeleteEntity($oEntity) {
		$res=$this->oMapperORM->DeleteEntity($oEntity);
		if ($res) {
			// сбрасываем кеш
			$sEntity=$this->Plugin_GetRootDelegater('entity',get_class($oEntity));
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array($sEntity.'_delete'));

            // Обновление связей many_to_many
            $aRelationsData = $oEntity->getRelationsData();
            foreach ($oEntity->_getRelations() as $sRelName => $aRelation) {
                if ($aRelation[0] == EntityORM::RELATION_TYPE_MANY_TO_MANY) {
                    $this->_deleteManyToManySet($aRelation[3], $aRelation[4], $oEntity->_getPrimaryKeyValue());
                }
            }

			return $oEntity;
		}
		return false;
	}	
	
	protected function _ReloadEntity($oEntity) {
		if($sPrimaryKey=$oEntity->_getPrimaryKey()) {
			if($sPrimaryKeyValue=$oEntity->_getDataOne($sPrimaryKey)) {
				if($oEntityNew=$this->GetByFilter(array($sPrimaryKey=>$sPrimaryKeyValue),Engine::GetEntityName($oEntity))) {
					$oEntity->_setData($oEntityNew->_getData());
					$oEntity->_setRelationsData(array());
					return $oEntity;
				}
			}
		}
		return false;
	}
	
	
	protected function _ShowColumnsFrom($oEntity) {
		$res=$this->oMapperORM->ShowColumnsFrom($oEntity);
		return $res;
	}	
	
	
	protected function _GetChildrenOfEntity($oEntity) {
		if(in_array(EntityORM::RELATION_TYPE_TREE,$oEntity->_getRelations())) {
			$aRelationsData=$oEntity->_getRelationsData();
			if(array_key_exists('children',$aRelationsData)) {
				$aChildren=$aRelationsData['children'];
			} else {
				$aChildren=array();
				if($sPrimaryKey=$oEntity->_getPrimaryKey()) {
					if($sPrimaryKeyValue=$oEntity->_getDataOne($sPrimaryKey)) {
						$aChildren=$this->GetItemsByFilter(array('parent_id'=>$sPrimaryKeyValue),Engine::GetEntityName($oEntity));
					}
				}
			}
			if(is_array($aChildren)) {
				$oEntity->setChildren($aChildren);
				return $aChildren;
			}
		}
		return false;
	}

	
	protected function _GetParentOfEntity($oEntity) {
		if(in_array(EntityORM::RELATION_TYPE_TREE,$oEntity->_getRelations())) {
			$aRelationsData=$oEntity->_getRelationsData();
			if(array_key_exists('parent',$aRelationsData)) {
				$oParent=$aRelationsData['parent'];
			} else {
				$oParent='%%NULL_PARENT%%';
				if($sPrimaryKey=$oEntity->_getPrimaryKey()) {
					if($sParentId=$oEntity->getParentId()) {
						$oParent=$this->GetByFilter(array($sPrimaryKey=>$sParentId),Engine::GetEntityName($oEntity));
					}
				}
			}
			if(!is_null($oParent)) {
				$oEntity->setParent($oParent);
				return $oParent;
			}
		}
		return false;
	}
	
	
	protected function _GetAncestorsOfEntity($oEntity) {
		if(in_array(EntityORM::RELATION_TYPE_TREE,$oEntity->_getRelations())) {
			$aRelationsData=$oEntity->_getRelationsData();
			if(array_key_exists('ancestors',$aRelationsData)) {
				$aAncestors=$aRelationsData['ancestors'];
			} else {
				$aAncestors=array();
				$oEntityParent=$oEntity->getParent();
				while(is_object($oEntityParent)) {
					$aAncestors[]=$oEntityParent;
					$oEntityParent=$oEntityParent->getParent();
				}
			}
			if(is_array($aAncestors)) {
				$oEntity->setAncestors($aAncestors);
				return $aAncestors;
			}
		}
		return false;
	}	
	
	
	protected function _GetDescendantsOfEntity($oEntity) {
		if(in_array(EntityORM::RELATION_TYPE_TREE,$oEntity->_getRelations())) {
			$aRelationsData=$oEntity->_getRelationsData();
			if(array_key_exists('descendants',$aRelationsData)) {
				$aDescendants=$aRelationsData['descendants'];
			} else {
				$aDescendants=array();
				if($aChildren=$oEntity->getChildren()) {
					$aTree=self::buildTree($aChildren);
					foreach($aTree as $aItem) {
						$aDescendants[] = $aItem['entity'];
					}
				}
			}
			if(is_array($aDescendants)) {
				$oEntity->setDescendants($aDescendants);
				return $aDescendants;
			}
		}
		return false;
	}
	

	public function LoadTree($aFilter=array(),$sEntityFull=null) {
		if (is_null($sEntityFull)) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.Engine::GetModuleName(get_class($this));
		} elseif (!substr_count($sEntityFull,'_')) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.$sEntityFull;
		}
		if($oEntityDefault=Engine::GetEntity($sEntityFull)) {
			if(in_array(EntityORM::RELATION_TYPE_TREE,$oEntityDefault->_getRelations())) {
				if($sPrimaryKey=$oEntityDefault->_getPrimaryKey()) {
					if($aItems=$this->GetItemsByFilter($aFilter,$sEntityFull)) {
						$aItemsById = array();
						$aItemsByParentId = array();
						foreach($aItems as $oEntity) {
							$oEntity->setChildren(array());
							$aItemsById[$oEntity->_getDataOne($sPrimaryKey)] = $oEntity;
							if(empty($aItemsByParentId[$oEntity->getParentId()])) {
								$aItemsByParentId[$oEntity->getParentId()] = array();
							}
							$aItemsByParentId[$oEntity->getParentId()][] = $oEntity;
						}
						foreach($aItemsByParentId as $iParentId=>$aItems) {
							if($iParentId > 0) {
								$aItemsById[$iParentId]->setChildren($aItems);
								foreach($aItems as $oEntity) {
									$oEntity->setParent($aItemsById[$iParentId]);
								}
							}
						}
						return $aItemsByParentId[0];
					}
				}
			}
		}
		return false;
	}
	
	public function GetByFilter($aFilter=array(),$sEntityFull=null) {
		if (is_null($sEntityFull)) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.Engine::GetModuleName(get_class($this));
		} elseif (!substr_count($sEntityFull,'_')) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.$sEntityFull;
		}
		
		return $this->oMapperORM->GetByFilter($aFilter,$sEntityFull);
	}
	
	public function GetItemsByFilter($aFilter=array(),$sEntityFull=null) {
		if (is_null($sEntityFull)) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.Engine::GetModuleName(get_class($this));
		} elseif (!substr_count($sEntityFull,'_')) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.$sEntityFull;
		}	

        // Если параметр #cache указан и пуст, значит игнорируем кэширование для запроса
        if (isset($aFilter['#cache']) && !$aFilter['#cache']) {
            $aEntities=$this->oMapperORM->GetItemsByFilter($aFilter,$sEntityFull);
        } else {
            $sEntityFullRoot=$this->Plugin_GetRootDelegater('entity',$sEntityFull);
            $sCacheKey=$sEntityFullRoot.'_items_by_filter_'.serialize($aFilter);
            $aCacheTags=array($sEntityFullRoot.'_save',$sEntityFullRoot.'_delete');
            $iCacheTime=60*60*24; // скорее лучше хранить в свойстве сущности, для возможности выборочного переопределения
            // переопределяем из параметров
            if (isset($aFilter['#cache'][0])) $sCacheKey=$aFilter['#cache'][0];
            if (isset($aFilter['#cache'][1])) $aCacheTags=$aFilter['#cache'][1];
            if (isset($aFilter['#cache'][2])) $iCacheTime=$aFilter['#cache'][2];

            if (false === ($aEntities = $this->Cache_Get($sCacheKey))) {
                $aEntities=$this->oMapperORM->GetItemsByFilter($aFilter,$sEntityFull);
                $this->Cache_Set($aEntities,$sCacheKey, $aCacheTags, $iCacheTime);
            }
        }
		/**
		 * Если необходимо подцепить связанные данные
		 */
		if (count($aEntities) and isset($aFilter['#with'])) {
			if (!is_array($aFilter['#with'])) {
				$aFilter['#with']=array($aFilter['#with']);
			}
			$oEntityEmpty=Engine::GetEntity($sEntityFull);
			$aRelations=$oEntityEmpty->_getRelations();
			$aEntityKeys=array();
			foreach ($aFilter['#with'] as $sRelationName) {
				$sRelType=$aRelations[$sRelationName][0];
				$sRelEntity=$this->Plugin_GetRootDelegater('entity',$aRelations[$sRelationName][1]); // получаем корневую сущность, без учета наследников
				$sRelKey=$aRelations[$sRelationName][2];
				
				if (!array_key_exists($sRelationName,$aRelations) or !in_array($sRelType,array(EntityORM::RELATION_TYPE_BELONGS_TO,EntityORM::RELATION_TYPE_HAS_ONE))) {
					throw new Exception("The entity <{$sEntityFull}> not have relation <{$sRelationName}>");
				}

				/**
				 * Формируем список ключей
				 */
				foreach ($aEntities as $oEntity) {
					$aEntityKeys[$sRelKey][]=$oEntity->_getDataOne($sRelKey);
				}
				$aEntityKeys[$sRelKey]=array_unique($aEntityKeys[$sRelKey]);
				
				/**
				 * Делаем общий запрос по всем ключам
				 */
				$oRelEntityEmpty=Engine::GetEntity($sRelEntity);
				$sRelModuleName=Engine::GetModuleName($sRelEntity);
				$sRelEntityName=Engine::GetEntityName($sRelEntity);
				$sRelPluginPrefix=Engine::GetPluginPrefix($sRelEntity);
				$sRelPrimaryKey = method_exists($oRelEntityEmpty,'_getPrimaryKey') ? func_camelize($oRelEntityEmpty->_getPrimaryKey()) : 'Id';								
				$aRelData=Engine::GetInstance()->_CallModule("{$sRelPluginPrefix}{$sRelModuleName}_get{$sRelEntityName}ItemsByArray{$sRelPrimaryKey}", array($aEntityKeys[$sRelKey]));
				
				/**
			 	 * Собираем набор
				 */
				foreach ($aEntities as $oEntity) {
					if (isset($aRelData[$oEntity->_getDataOne($sRelKey)])) {
						$oEntity->_setData(array($sRelationName => $aRelData[$oEntity->_getDataOne($sRelKey)]));
					}
				}
			}
			
		}

        /**
	     * Returns assotiative array, indexed by PRIMARY KEY or another field.
		 */
		if (in_array('#index-from-primary', $aFilter) || !empty($aFilter['#index-from'])) {
            $aEntities = $this->_setIndexesFromField($aEntities, $aFilter);
		}
		
		/**
		 * Если запрашиваем постраничный список, то возвращаем сам список и общее количество записей
		 */
		if (isset($aFilter['#page'])) {
			return array('collection'=>$aEntities,'count'=>$this->GetCountItemsByFilter($aFilter,$sEntityFull));
		}
		
		return $aEntities;
	}

    /**
     * Returns assotiative array, indexed by PRIMARY KEY or another field.
     * @param <type> $oEntity
     * @param <type> $aFilter
     * @return <type>
     */
    protected function _setIndexesFromField($aEntities, $aFilter)
    {
        $aIndexedEntities=array();
        foreach ($aEntities as $oEntity) {
            $sKey = in_array('#index-from-primary', $aFilter) || ( !empty($aFilter['#index-from']) && $aFilter['#index-from'] == '#primary' ) ?
                $oEntity->_getPrimaryKey() :
                $oEntity->_getField($aFilter['#index-from']);
            $aIndexedEntities[$oEntity->_getDataOne($sKey)]=$oEntity;
        }
		return $aIndexedEntities;
    }
	
	public function GetCountItemsByFilter($aFilter=array(),$sEntityFull=null) {
		if (is_null($sEntityFull)) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.Engine::GetModuleName(get_class($this));
		} elseif (!substr_count($sEntityFull,'_')) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.$sEntityFull;
		}
        // Если параметр #cache указан и пуст, значит игнорируем кэширование для запроса
        if (isset($aFilter['#cache']) && !$aFilter['#cache']) {
            $iCount=$this->oMapperORM->GetCountItemsByFilter($aFilter,$sEntityFull);
        } else {
            $sEntityFullRoot=$this->Plugin_GetRootDelegater('entity',$sEntityFull);
            $sCacheKey=$sEntityFullRoot.'_count_items_by_filter_'.serialize($aFilter);
            $aCacheTags=array();
            $iCacheTime=60*60*24; // скорее лучше хранить в свойстве сущности, для возможности выборочного переопределения
            // переопределяем из параметров
            if (isset($aFilter['#cache'][0])) $sCacheKey=$aFilter['#cache'][0];
            if (isset($aFilter['#cache'][1])) $aCacheTags=$aFilter['#cache'][1];
            if (isset($aFilter['#cache'][2])) $iCacheTime=$aFilter['#cache'][2];

            if (false === ($iCount = $this->Cache_Get($sCacheKey))) {
                $iCount=$this->oMapperORM->GetCountItemsByFilter($aFilter,$sEntityFull);
                $this->Cache_Set($aEntities,$sCacheKey, $aCacheTags, $iCacheTime);
            }
        }

		return $iCount;
	}
	
	/*
	 * Returns associative array of entities, indexed by PRIMARY KEY
	 */
	public function GetItemsByArray($aFilter,$sEntityFull=null) {                            
		foreach ($aFilter as $k=>$v) { 
				$aFilter["{$k} IN"]=$v; 
				unset($aFilter[$k]); 
		}
		$aFilter[] = '#index-from-primary';
		return $this->GetItemsByFilter($aFilter,$sEntityFull); 
	} 

	public function GetItemsByJoinTable($aJoinData=array(),$sEntityFull=null) {
		if (is_null($sEntityFull)) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.Engine::GetModuleName(get_class($this));
		} elseif (!substr_count($sEntityFull,'_')) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.$sEntityFull;
		}

        // Если параметр #cache указан и пуст, значит игнорируем кэширование для запроса
        if (isset($aJoinData['#cache']) && !$aJoinData['#cache']) {
            $aEntities = $this->oMapperORM->GetItemsByJoinTable($aJoinData,$sEntityFull);
        } else {
            $sEntityFullRoot=$this->Plugin_GetRootDelegater('entity',$sEntityFull);
            $sCacheKey=$sEntityFullRoot.'_items_by_join_table_'.serialize($aJoinData);
            $aCacheTags=array($sEntityFullRoot.'_save',$sEntityFullRoot.'_delete');
            $iCacheTime=60*60*24; // скорее лучше хранить в свойстве сущности, для возможности выборочного переопределения
            // переопределяем из параметров
            if (isset($aJoinData['#cache'][0])) $sCacheKey=$aJoinData['#cache'][0];
            if (isset($aJoinData['#cache'][1])) $aCacheTags=$aJoinData['#cache'][1];
            if (isset($aJoinData['#cache'][2])) $iCacheTime=$aJoinData['#cache'][2];

             // Добавление тега для обработки MANY_TO_MANY
            $aCacheTags[] = 'm2m_'.$aJoinData['#relation_key'].$aJoinData['#by_key'].$aJoinData['#by_value'];
            if (false === ($aEntities = $this->Cache_Get($sCacheKey))) {
                $aEntities = $this->oMapperORM->GetItemsByJoinTable($aJoinData,$sEntityFull);
                $this->Cache_Set($aEntities,$sCacheKey, $aCacheTags, $iCacheTime);
            }
        }

        if (in_array('#index-from-primary', $aJoinData) || !empty($aJoinData['#index-from'])) {
            $aEntities = $this->_setIndexesFromField($aEntities, $aJoinData);
		}

        /**
		 * Если запрашиваем постраничный список, то возвращаем сам список и общее количество записей
		 */
		if (isset($aFilter['#page'])) {
			return array('collection'=>$aEntities,'count'=>$this->GetCountItemsByJoinTable($aJoinData,$sEntityFull));
		}

        return $aEntities;
	}

    public function GetCountItemsByJoinTable($aJoinData=array(),$sEntityFull=null) {
		if (is_null($sEntityFull)) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.Engine::GetModuleName(get_class($this));
		} elseif (!substr_count($sEntityFull,'_')) {
			$sEntityFull=Engine::GetPluginPrefix($this).'Module'.Engine::GetModuleName($this).'_Entity'.$sEntityFull;
		}
        // Если параметр #cache указан и пуст, значит игнорируем кэширование для запроса
        if (isset($aJoinData['#cache']) && !$aJoinData['#cache']) {
            $iCount = $this->oMapperORM->GetCountItemsByJoinTable($aJoinData,$sEntityFull);
        } else {
            $sEntityFullRoot=$this->Plugin_GetRootDelegater('entity',$sEntityFull);
            $sCacheKey=$sEntityFullRoot.'_count_items_by_join_table_'.serialize($aJoinData);
            $aCacheTags=array();
            $iCacheTime=60*60*24; // скорее лучше хранить в свойстве сущности, для возможности выборочного переопределения
            // переопределяем из параметров
            if (isset($aJoinData['#cache'][0])) $sCacheKey=$aJoinData['#cache'][0];
            if (isset($aJoinData['#cache'][1])) $aCacheTags=$aJoinData['#cache'][1];
            if (isset($aJoinData['#cache'][2])) $iCacheTime=$aJoinData['#cache'][2];

            if (false === ($iCount = $this->Cache_Get($sCacheKey))) {
                $iCount = $this->oMapperORM->GetCountItemsByJoinTable($aJoinData,$sEntityFull);
                $this->Cache_Set($iCount,$sCacheKey, $aCacheTags, $iCacheTime);
            }
        }

        return $iCount;
	}
	
	public function __call($sName,$aArgs) {
		if (preg_match("@^add([\w]+)$@i",$sName,$aMatch)) {
			return $this->_AddEntity($aArgs[0]);
		}
		
		if (preg_match("@^update([\w]+)$@i",$sName,$aMatch)) {
			return $this->_UpdateEntity($aArgs[0]);
		}
		
		if (preg_match("@^save([\w]+)$@i",$sName,$aMatch)) {
			return $this->_SaveEntity($aArgs[0]);
		}
		
		if (preg_match("@^delete([\w]+)$@i",$sName,$aMatch)) {
			return $this->_DeleteEntity($aArgs[0]);
		}
		
		if (preg_match("@^reload([\w]+)$@i",$sName,$aMatch)) {
			return $this->_ReloadEntity($aArgs[0]);
		}

		if (preg_match("@^showcolumnsfrom([\w]+)$@i",$sName,$aMatch)) {
			return $this->_ShowColumnsFrom($aArgs[0]);
		}

		if (preg_match("@^getchildrenof([\w]+)$@i",$sName,$aMatch)) {
			return $this->_GetChildrenOfEntity($aArgs[0]);
		}
		
		if (preg_match("@^getparentof([\w]+)$@i",$sName,$aMatch)) {
			return $this->_GetParentOfEntity($aArgs[0]);
		}
		
		if (preg_match("@^getdescendantsof([\w]+)$@i",$sName,$aMatch)) {
			return $this->_GetDescendantsOfEntity($aArgs[0]);
		}
		
		if (preg_match("@^getancestorsof([\w]+)$@i",$sName,$aMatch)) {
			return $this->_GetAncestorsOfEntity($aArgs[0]);
		}
		
		if (preg_match("@^loadtreeof([\w]+)$@i",$sName,$aMatch)) {
			$sEntityFull = array_key_exists(1,$aMatch) ? $aMatch[1] : null;
			return $this->LoadTree($sEntityFull);
		}
		
		$sNameUnderscore=func_underscore($sName);
		$iEntityPosEnd=0; 
		if(strpos($sNameUnderscore,'_items')>=3) {
			$iEntityPosEnd=strpos($sNameUnderscore,'_items');
		} else if(strpos($sNameUnderscore,'_by')>=3) {
			$iEntityPosEnd=strpos($sNameUnderscore,'_by');
		} else if(strpos($sNameUnderscore,'_all')>=3) {
			$iEntityPosEnd=strpos($sNameUnderscore,'_all');
		}
		if($iEntityPosEnd && $iEntityPosEnd > 4) {
			$sEntityName=substr($sNameUnderscore,4,$iEntityPosEnd-4);
		} else {
			$sEntityName=func_underscore(Engine::GetModuleName($this)).'_';
			$sNameUnderscore=substr_replace($sNameUnderscore,$sEntityName,4,0);
			$iEntityPosEnd=strlen($sEntityName)-1+4;
		}

		$sNameUnderscore=substr_replace($sNameUnderscore,str_replace('_','',$sEntityName),4,$iEntityPosEnd-4);

		$sEntityName=func_camelize($sEntityName);
		
		/**
		 * getUserItemsByFilter() get_user_items_by_filter
		 */
		if (preg_match("@^get_([a-z]+)((_items)|())_by_filter$@i",$sNameUnderscore,$aMatch)) {
			if ($aMatch[2]=='_items') {
				return $this->GetItemsByFilter($aArgs[0],$sEntityName);
			} else {				
				return $this->GetByFilter($aArgs[0],$sEntityName);
			}
		}
		
		/**
		 * getUserItemsByArrayId() get_user_items_by_array_id
		 */
		if (preg_match("@^get_([a-z]+)_items_by_array_([_a-z]+)$@i",$sNameUnderscore,$aMatch)) { 
			return $this->GetItemsByArray(array($aMatch[2]=>$aArgs[0]),$sEntityName); 
		}
		
		/**
		 * getUserItemsByJoinTable() get_user_items_by_join_table
		 */
		if (preg_match("@^get_([a-z]+)_items_by_join_table$@i",$sNameUnderscore,$aMatch)) {
			return $this->GetItemsByJoinTable($aArgs[0],func_camelize($sEntityName));
		}
		
		/**
		 * getUserByLogin()					get_user_by_login
		 * getUserByLoginAndMail()			get_user_by_login_and_mail
		 * getUserItemsByName()				get_user_items_by_name
		 * getUserItemsByNameAndActive()	get_user_items_by_name_and_active	
		 * getUserItemsByDateRegisterGte()	get_user_items_by_date_register_gte		(>=)
		 * getUserItemsByProfileNameLike()	get_user_items_by_profile_name_like
		 * getUserItemsByCityIdIn()			get_user_items_by_city_id_in
		 */		
		if (preg_match("@^get_([a-z]+)((_items)|())_by_([_a-z]+)$@i",$sNameUnderscore,$aMatch)) {
			$aAliases = array( '_gte' => ' >=', '_lte' => ' <=', '_gt' => ' >', '_lt' => ' <', '_like' => ' LIKE', '_in' => ' IN' );
			$sSearchParams = str_replace(array_keys($aAliases),array_values($aAliases),$aMatch[5]);
			$aSearchParams=explode('_and_',$sSearchParams);
			$aSplit=array_chunk($aArgs,count($aSearchParams));			
			$aFilter=array_combine($aSearchParams,$aSplit[0]);
			if (isset($aSplit[1][0])) {
				$aFilter=array_merge($aFilter,$aSplit[1][0]);
			}
			if ($aMatch[2]=='_items') {
				return $this->GetItemsByFilter($aFilter,$sEntityName);
			} else {				
				return $this->GetByFilter($aFilter,$sEntityName);
			}
		}
		
		/**
		 * getUserAll()			get_user_all 		OR
		 * getUserItemsAll()	get_user_items_all
		 */
		if (preg_match("@^get_([a-z]+)_all$@i",$sNameUnderscore,$aMatch) ||
			preg_match("@^get_([a-z]+)_items_all$@i",$sNameUnderscore,$aMatch)
			) {
			$aFilter=array();
			if (isset($aArgs[0]) and is_array($aArgs[0])) {
				$aFilter=$aArgs[0];
			}
			return $this->GetItemsByFilter($aFilter,$sEntityName);
		}
		
		return $this->oEngine->_CallModule($sName,$aArgs);
	}
	
	static function buildTree($aItems,$aList=array(),$iLevel=0) {
		foreach($aItems as $oEntity) {
			$aChildren=$oEntity->getChildren();
			$bHasChildren = !empty($aChildren); 
			$sEntityId = $oEntity->_getDataOne($oEntity->_getPrimaryKey());
			$aList[$sEntityId] = array(
				'entity'		 => $oEntity,
				'parent_id'		 => $oEntity->getParentId(),
				'children_count' => $bHasChildren ? count($aChildren) : 0,
				'level'			 => $iLevel,
			);
			if($bHasChildren) {
				$aList=self::buildTree($aChildren,$aList,$iLevel+1);
			}
		}
		return $aList;
	}

    /**
     * Обновление связи many_to_many в бд
     * @param <type> $aRelation Соответствующий связи элемент массива из $oEntityORM->aRelations
     * @param <type> $aRelationData Соответствующий связи элемент массива из $oEntityORM->aRelationsData
     * @param <type> $iEntityId Id сущности, для которой обновляются связи
     * @return <type>
     */
    protected function _updateManyToManySet($aRelation, $aRelationData, $iEntityId)
    {
        /*
         * Описание параметров связи many_to_many
         * Для примера возьмём такую связь в сущности $oTopic
         * 'tags' => array(self::RELATION_TYPE_MANY_TO_MANY,'ModuleTopic_EntityTag', 'tag_id',  'db.table.topic_tag_rel', 'topic_id'),
         * И используется таблица связи
         * table prefix_topic_tag_rel
         *  topic_id | ефп_id
         * Тогда тут
         * [0] -> self::RELATION_TYPE_MANY_TO_MANY - тип связи
         * [1] -> 'ModuleTopic_EntityTag' - имя сущности объектов связи
         * [2] -> 'tag_id' - названия столбца в таблице связи, в котором содержатся id объектов связи, в нашем случае тегов.
         * [3] -> 'db.table.topic_tag_rel' - алиас (идентификатор из конфига) таблицы связи.
         *      Обратите внмание на то, что ORM для определения таблиц сущностей использует модуль и название сущности, то есть
         *      если мы захотим таблицу связи назвать prefix_topic_tag, что, в общем-то, логично, то будет конфликт имён, потому что
         *      ModuleTopic_EntityTag также преобразуется в prefix_topic_tag.
         *      Поэтому необходимо следить за корректным именованием таблиц (точнее алиасов в конфиге, сами таблицы в бд могут
         *      называться как угодно). В данном примере используется суффикс '_rel'.
         * [4] -> 'topic_id' - название столбца в таблице связи, в котором содержатся id сущности, для которой объявляется связь,
         *      в нашем случае топиков
         */
        $aSavedSet = $this->oMapperORM->getManyToManySet($aRelation[3], $aRelation[4], $iEntityId, $aRelation[2]);
        $aCurrentSet = array();
        foreach ($aRelationData as $oEntity) {
            $aCurrentSet[] = $oEntity->_getDataOne($oEntity->_getPrimaryKey());
        }
        if ($aSavedSet == $aCurrentSet) return;
        $aInsertSet = array_diff($aCurrentSet, $aSavedSet);
        $aDeleteSet = array_diff($aSavedSet, $aCurrentSet);
        $this->oMapperORM->updateManyToManySet($aRelation[3], $aRelation[4], $iEntityId, $aRelation[2], $aInsertSet, $aDeleteSet);
    }

    /**
     * Удаление связи many_to_many в бд
     * @param <type> $sDbTableAlias Алиас имени таблицы связи
     * @param <type> $sEntityKey Название поля в таблице связи с id сущности, для которой удаляются связи.
     * @param <type> $iEntityId Id сущнсоти, для который удаляются связи
     */
    protected function _deleteManyToManySet($sDbTableAlias, $sEntityKey, $iEntityId)
    {
        $this->oMapperORM->deleteManyToManySet($sDbTableAlias, $sEntityKey, $iEntityId);
    }
}
?>