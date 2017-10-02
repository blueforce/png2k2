<?php
/**
 * @version		1.0.5
 * @package		png4k2 (plugin)
 * @author    		JoomlaChamp - http://www.joomlachamp.com
 * @copyright		Copyright (c) 2012 - 2014 Redpanda OE (redpanda.gr).
 * @license		GPLv2 or later
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

// Load the K2 plugin API
JLoader::register('K2Plugin', JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'lib'.DS.'k2plugin.php');

class plgK2Png4k2 extends K2Plugin {
	
	var $pluginName = 'png4k2';
	var $pluginNameHumanReadable = 'Png images for K2 items';
	var $_new = null;
	var $_old = null;

	function plgK2Png4k2(&$subject, $params) {
		
		$this->_new = new JObject;
		$this->_old = new JObject;
		
		parent::__construct($subject, $params);
	}
	
	function onBeforeK2Save(&$item, $isNew)
	{
		
		jimport('joomla.filesystem.file');
		jimport('joomla.filesystem.folder');
		
		if($_FILES["image"] && $_FILES["image"]["error"] === 0) {
			$this->_new->ext	= strtolower(end(explode(".",$_FILES["image"]["name"]))); 
			$path_parts 		= pathinfo($_FILES["image"]["tmp_name"]);
			if($this->_new->ext!="jpg") {
				$curName	= $path_parts['filename'];
				$copyName	= "copy".$item->id."_".$path_parts['filename'];
				$fullName 	= str_replace($curName,$copyName,$_FILES["image"]["tmp_name"]);
				
				if(copy($_FILES["image"]["tmp_name"], $fullName))
				{
					$_FILES["image-clone"] = $_FILES["image"];
					$_FILES["image-clone"]["tmp_name"] = $fullName;
				}
			}
		}
		
	}

	function onAfterK2Save(&$item, $isNew)
	{
		
		jimport('joomla.filesystem.file');
		jimport('joomla.filesystem.folder');
		JTable::addIncludePath(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'tables');
		require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'lib'.DS.'class.upload.php');
		require_once (JPATH_SITE.DS.'plugins'.DS.'k2'.DS.'png4k2'.DS.'helpers'.DS.'gifsplit.php');
		
		$db = JFactory::getDBO();
		
		$files 		= JRequest::get('files');
		$existingImage 	= JRequest::getVar('existingImage');
		$params 	= JComponentHelper::getParams('com_k2');
		
		if (($files['image-clone']['error'] === 0 || $existingImage) && !JRequest::getBool('del_image'))
		{
			// lets get in an array all the cur images 
			$oldSrc 	= glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'src'.DS.md5("Image".$item->id).'*');
			$oldCache 	= glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache'.DS.md5("Image".$item->id).'_*');
			$oldImgs 	= array_merge((array)$oldSrc, (array)$oldCache);
			if(count($oldImgs)) {
				foreach($oldImgs as $oldImg)
				{
					JFile::delete($oldImg);
				}
			}
			
			if($existingImage) {
				$image = JPATH_SITE.DS.JPath::clean($existingImage);
			}
			if ($files['image-clone']['error'] === 0)
			{
				$image = $files['image-clone'];
			}

			$handle = new Upload($image);
			$handle->allowed = array('image/*');
			$handle->no_upload_check = true;

			if ($handle->uploaded)
			{
				$errors 	= array();
				$newImgs	= array();
				$isAnimated 	= false;
				
				//Image params
				$category = JTable::getInstance('K2Category', 'Table');
				$category->load($item->catid);
				$cparams = class_exists('JParameter') ? new JParameter($category->params) : new JRegistry($category->params);

				if ($cparams->get('inheritFrom'))
				{
					$masterCategoryID = $cparams->get('inheritFrom');
					$query = "SELECT * FROM #__k2_categories WHERE id=".(int)$masterCategoryID;
					$db->setQuery($query, 0, 1);
					$masterCategory = $db->loadObject();
					$cparams = class_exists('JParameter') ? new JParameter($masterCategory->params) : new JRegistry($masterCategory->params);
				}

				$params->merge($cparams);

				//Original image
				$savepath = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'src';
				$handle->jpeg_quality = 100;
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = md5("Image".$item->id);
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for Source image";
				} else {
					$newImgs[] 		= $handle->file_dst_pathname;
				}

				if($this->_isAnimatedGif($handle->file_dst_pathname))  $isAnimated = true; 

				$filename = $handle->file_dst_name_body;
				$savepath = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache';
				
				//XLarge image
				$handle->image_resize = true;
				$handle->image_ratio_y = true;
				$handle->jpeg_quality = $params->get('imagesQuality');
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = $filename.'_XL';
				if (JRequest::getInt('itemImageXL'))
				{
					$imageWidth = JRequest::getInt('itemImageXL');
				}
				else
				{
					$imageWidth = $params->get('itemImageXL', '800');
				}
				$handle->image_x = $imageWidth;
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for XL image";
				} else {
					$newImgs[] = $handle->file_dst_pathname;
				}

				//Large image
				$handle->image_resize = true;
				$handle->image_ratio_y = true;
				$handle->jpeg_quality = $params->get('imagesQuality');
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = $filename.'_L';
				if (JRequest::getInt('itemImageL'))
				{
					$imageWidth = JRequest::getInt('itemImageL');
				}
				else
				{
					$imageWidth = $params->get('itemImageL', '600');
				}
				$handle->image_x = $imageWidth;
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for L image";
				} else {
					$newImgs[] = $handle->file_dst_pathname;
				}

				//Medium image
				$handle->image_resize = true;
				$handle->image_ratio_y = true;
				$handle->jpeg_quality = $params->get('imagesQuality');
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = $filename.'_M';
				if (JRequest::getInt('itemImageM'))
				{
					$imageWidth = JRequest::getInt('itemImageM');
				}
				else
				{
					$imageWidth = $params->get('itemImageM', '400');
				}
				$handle->image_x = $imageWidth;
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for M image";
				} else {
					$newImgs[] = $handle->file_dst_pathname;
				}

				//Small image
				$handle->image_resize = true;
				$handle->image_ratio_y = true;
				$handle->jpeg_quality = $params->get('imagesQuality');
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = $filename.'_S';
				if (JRequest::getInt('itemImageS'))
				{
					$imageWidth = JRequest::getInt('itemImageS');
				}
				else
				{
					$imageWidth = $params->get('itemImageS', '200');
				}
				$handle->image_x = $imageWidth;
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for S image";
				} else {
					$newImgs[] = $handle->file_dst_pathname;
				}

				//XSmall image
				$handle->image_resize = true;
				$handle->image_ratio_y = true;
				$handle->jpeg_quality = $params->get('imagesQuality');
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = $filename.'_XS';
				if (JRequest::getInt('itemImageXS'))
				{
					$imageWidth = JRequest::getInt('itemImageXS');
				}
				else
				{
					$imageWidth = $params->get('itemImageXS', '100');
				}
				$handle->image_x = $imageWidth;
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for XS image";
				} else {
					$newImgs[] = $handle->file_dst_pathname;
				}

				//Generic image
				$handle->image_resize = true;
				$handle->image_ratio_y = true;
				$handle->jpeg_quality = $params->get('imagesQuality');
				$handle->file_auto_rename = false;
				$handle->file_overwrite = true;
				$handle->file_new_name_body = $filename.'_Generic';
				$imageWidth = $params->get('itemImageGeneric', '300');
				$handle->image_x = $imageWidth;
				$handle->Process($savepath);
				if (!$handle->processed) {
					$errors[] = "Error for Generic image";
				} else {
					$newImgs[] = $handle->file_dst_pathname;
				}

				if ($files['image-clone']['error'] === 0)
					$handle->Clean();
				
				// lets check what sizes will resize the animated gif
				$aniSizes 	= $this->params->get('png4k2_anisizes', '');
				$aniSizes 	= explode(",",$aniSizes);
				$acSizes	= array("XL","L","M","S","XS","Generic");
				$resAniSizes 	= array_intersect($acSizes, $aniSizes);
				if(count($resAniSizes))
				{
					$o = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'src'.DS.$filename.'.gif';
					foreach($resAniSizes as $aniSize)
					{
						$this->_scaleImageFile($o,$params->get('itemImage'.$aniSize),0,$savepath.DS.$filename."_".$aniSize.".gif",4);
					}
				}
				
					
			} //echo $handle->log; die();

		}
		
		if(($_FILES["image"] && $_FILES["image"]["error"] === 0 && $this->_new->ext=="jpg") || JRequest::getBool('del_image')) {
			
			$extensions = array("gif","jpeg","png");
			if(JRequest::getBool('del_image')) $extensions[] = "jpg";
			
			$oldSrc 	= glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'src'.DS.md5("Image".$item->id).'*.{'.implode(',',$extensions).'}', GLOB_BRACE);
			$oldCache 	= glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache'.DS.md5("Image".$item->id).'*.{'.implode(',',$extensions).'}', GLOB_BRACE);
			$oldImages 	= array_merge((array)$oldSrc, (array)$oldCache);
			
			if(count($oldImages)) {
				foreach($oldImages as $oldImage) {
					JFile::delete($oldImage);
				}
			}
			
		}
		
		
	}
	
	function onK2PrepareContent(&$item, &$params, $limitstart=0)
	{
		// do not exec for category objects
		if(!isset($item->catid)) return;
		
		//Image
		$item->imageXSmall = '';
		$item->imageSmall = '';
		$item->imageMedium = '';
		$item->imageLarge = '';
		$item->imageXLarge = '';
		
		// image array
		$imgs = array();
		$imgs["_XS"] = "imageXSmall";
		$imgs["_S"] = "imageSmall";
		$imgs["_M"] = "imageMedium";
		$imgs["_L"] = "imageLarge";
		$imgs["_XL"] = "imageXLarge";
		$imgs["_Generic"] = "imageGeneric";

		$date = JFactory::getDate($item->modified);
		$timestamp = '?t='.$date->toUnix();
		$md5 = md5("Image".$item->id);
		
		$resultImages = glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache'.DS.$md5.'_*');
		if(count($resultImages)) {
			foreach($resultImages as $resultImage) {
				
				foreach($imgs as $imgChar=>$imgValue)
				{
					$pos = stripos($resultImage, $imgChar);
					
					if ($pos !== false) {
						
						$info = pathinfo($resultImage);
						
						$item->$imgValue = JURI::root().'media/k2/items/cache/'.$md5.$imgChar.'.'.$info['extension'];
						if ($params->get('imageTimestamp'))
						{
							$item->$imgValue .= $timestamp;
						}
					}
				}
				
			}
		}
		$image = 'image'.$params->get('itemImgSize', 'Small');
		if (isset($item->$image))
			$item->image = $item->$image;
	}
	
	function onRenderAdminForm(&$item, $type, $tab = '')
	{
		if($type=="item")
		{
		
			$date = JFactory::getDate($item->modified);
			$timestamp = '?t='.$date->toUnix();
			
			$resultLarge = glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache'.DS.md5("Image".$item->id).'_L*.{gif,jpg,jpeg,png}', GLOB_BRACE);
			if(count($resultLarge)) {
				foreach($resultLarge as $large) {
					
					$info = pathinfo($large);
					$item->image = JURI::root().'media/k2/items/cache/'.md5("Image".$item->id).'_L.'.$info['extension'].$timestamp;
					
				}
			}
			
			$resultSmall = glob (JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache'.DS.md5("Image".$item->id).'_S*.{gif,jpg,jpeg,png}', GLOB_BRACE);
			if(count($resultSmall)) {
				foreach($resultSmall as $small) {
					
					$info = pathinfo($small);
					$item->thumb = JURI::root().'media/k2/items/cache/'.md5("Image".$item->id).'_S.'.$info['extension'].$timestamp;
					
				}
			}
		
		}
	}
	
	function _isAnimatedGif($filename) {
		return (bool)preg_match('#(\x00\x21\xF9\x04.{4}\x00\x2C.*){2,}#s', file_get_contents($filename));
	}
	
	function _scaleImageFile($fileSrc, $w, $h, $saveTo, $resizemethod = 1){
		$delays = array(5);
		
		if(file_exists($fileSrc) && is_numeric($w)) {
			if(list($width, $height, $type, $attr) = getimagesize($fileSrc)){
		
				if(!$h) $h = (($height * $w) / $width);
	
				if($type == 1 && $this->_isAnimatedGif($fileSrc)){
					$gif = new GIFDecoder(file_get_contents($fileSrc));
					$delays = $gif->GIFGetDelays();
					$oldimg_a = $gif->GIFGetFrames();
					if(sizeof($oldimg_a) <= 0) return false;
					
					for($i = 0; $i < sizeof($oldimg_a); $i++){
						$oldimg_a[$i] = imagecreatefromstring($oldimg_a[$i]);
					}
					
				}else{
				    if(! ($oldimg = $this->_loadImage($fileSrc, $type))) return false;
					$oldimg_a = array($oldimg);
				}
				$newimg_a = array();
				
				
				foreach($oldimg_a as $oldimg){
					$newimg = null;
		
					if($resizemethod == 4){
						$ratio = 1.0;
						$ratio_w = $width / $w;
						$ratio_h = $height / $h;
						$ratio = ($ratio_h < $ratio_w ? $ratio_h : $ratio_w);
						$neww = intval($width / $ratio);
						$newh = intval($height / $ratio);
						$tempimg = imagecreatetruecolor($neww, $newh);
						imagecopyresampled($tempimg, $oldimg, 0, 0, 0, 0, $neww, $newh, $width, $height);
						$clipw = 0; $cliph = 0;
						if($neww > $w) $clipw = $neww - $w;
						if($newh > $h) $cliph = $newh - $h;
		
		
						$cliptop = floor($cliph / 2);
						$clipleft = floor($clipw / 2);
						$newimg = imagecreatetruecolor($w, $h);
						imagecopy($newimg, $tempimg, 0, 0, $clipleft, $cliptop, $w, $h);
					}else if($resizemethod == 3){
						$newimg = imagecreatetruecolor($w, $h);
						imagecopyresampled($newimg, $oldimg, 0, 0, 0, 0, $w, $h, $width, $height);
					}else if($resizemethod == 2){
						$ratio = 1.0;
						$ratio_w = $width / $w;
						$ratio_h = $height / $h;
						$ratio = ($ratio_h > $ratio_w ? $ratio_h : $ratio_w);
						$newimg = imagecreatetruecolor(intval($width / $ratio), intval($height / $ratio));
						imagecopyresampled($newimg, $oldimg, 0, 0, 0, 0, intval($width / $ratio), intval($height / $ratio), $width, $height);
					}else{
						$ratio = 1.0;
						if($width > $w || $height > $h){
							$ratio = $width / $w;
							if(($height / $h) > $ratio) $ratio = $height / $h;	
						}
						$newimg = imagecreatetruecolor(intval($width / $ratio), intval($height / $ratio));
						imagecopyresampled($newimg, $oldimg, 0, 0, 0, 0, intval($width / $ratio), intval($height / $ratio), $width, $height);
					}
					array_push($newimg_a, $newimg);
				}
	
				if(sizeof($newimg_a) > 1){
						$newa = array();
						foreach($newimg_a as $i){
							ob_start();
							imagegif($i);
							$gifdata = ob_get_clean();
							array_push($newa, $gifdata);
						}
		
						$gifmerge = new GIFEncoder	(
									$newa,
									$delays,
									999,
									2,
									0, 0, 0,
									"bin"
							);	
						FWrite ( FOpen ( $saveTo, "wb" ), $gifmerge->GetAnimation ( ) );
				} else {
					$this->_outputImage($newimg, $saveTo);
				}            
	
		    
				foreach($newimg_a as $newimg){
					imagedestroy($newimg);
				}
			
				return true;
		    
			} else return false;
		}
		return false;
	}
	
	
	function _loadImage($fileSrc, $imgType){
	    switch ($imgType){
		case 1:   //   gif
		    return imagecreatefromgif($fileSrc);
		case 2:   //   jpeg
		    return imagecreatefromjpeg($fileSrc);
		case 3:  //   png
		    return imagecreatefrompng($fileSrc);
	    }
	    return false;
	}
	
	function _outputImage($img, $saveTo){
	    if(strlen($saveTo) > 0){
		imagejpeg($img, $saveTo, 90);
	    }
	
	    return true;
	}

} // End class
