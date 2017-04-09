<?php

namespace Bitrix\Sale\TradingPlatform\Vk\Api;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\IO;
use Bitrix\Sale\TradingPlatform\Timer;
use Bitrix\Sale\TradingPlatform\Vk\Vk;
use Bitrix\Sale\TradingPlatform\TimeIsOverException;


/**
 * Class ApiHelper - formatted and run requests to VK Api. Provide utility functions for help.
 * @package Bitrix\Sale\TradingPlatform\Vk\Api
 */
class ApiHelper
{
	private $vk;
	private $api;
	private $executer;
	
	/**
	 * ApiHelper constructor.
	 * @param $exportId - int, ID of export profile
	 */
	public function __construct($exportId)
	{
		if (empty($exportId))
			throw new ArgumentNullException('exportId');
		
		$this->vk = Vk::getInstance();
		$this->api = $this->vk->getApi($exportId);
		$this->executer = $this->vk->getExecuter($exportId);
	}
	
	
	/**
	 * Extract specified elements from array. Need to decrease of array size to post
	 *
	 * @param array $data - source array
	 * @param array $keys - array of keys, thst needed in new array
	 * @return array - array of extracted items
	 */
	public static function extractItemsFromArray($data = array(), $keys = array())
	{
		if (!isset($keys) || empty($keys))
			return $data;
		
		$newArr = array();
		foreach ($data as $value)
		{
			if (!is_array($value))
			{
				$newArr[] = $value;
			}
			else
			{
				$currArr = array();
				foreach ($keys as $k)
				{
					$currArr[$k] = $value[$k];
				}
				$newArr[] = $currArr;
			}
		}
		
		return $newArr;
	}
	
	
	/**
	 * Merge to arrays by reference key
	 *
	 * @param array $data
	 * @param array $result
	 * @param $referenceKey - main key in both arrays
	 * @return array
	 */
	public static function addResultToData($data = array(), $result = array(), $referenceKey)
	{
		if (empty($result) || !isset($referenceKey))
			return $data;
		
		foreach ($result as $item)
		{
			if (isset($data[$item[$referenceKey]]))
				$data[$item[$referenceKey]] += $item;
		}
		
		return $data;
	}
	
	
	/**
	 * Reformat array - change main (top level) key.
	 *
	 * @param array $data
	 * @param $mainKey
	 * @param string $keyRename - if isset, new main key will be rename
	 * @return array
	 */
	public static function changeArrayMainKey($data = array(), $mainKey, $keyRename = '')
	{
		if (!isset($mainKey))
			return $data;
		
		$result = array();
		foreach ($data as $item)
		{
			$result[$item[$mainKey]] = $item;
			if ($keyRename)
			{
				$result[$item[$mainKey]][$keyRename] = $result[$item[$mainKey]][$mainKey];
				unset($result[$item[$mainKey]][$mainKey]);
			}
		}
		
		return $result;
	}
	
	
	/**
	 * Check photo size, get upload server, upload photo and save them
	 *
	 * @param $data
	 * @param $vkGroupId
	 * @param $uploadType - type of photo. For other types used other params and methods
	 * @param null $timer - timer for control time of upload
	 * @return array - array of save photos results
	 * @throws SystemException
	 */
	public function uploadPhotos($data, $vkGroupId, $uploadType, Timer $timer = NULL)
	{
//		todo: this is a little kostyl. In cool variant we must separately do http-upload,
//		todo: and photo save run through execute method
//		todo: but now VK can't run savePhotoMethod through execute. Sadness ((

//		PARAMS set
		$photoSaveResults = array();
		switch ($uploadType)
		{
			case 'PRODUCT_MAIN_PHOTO':
				$uploadServerMethod = 'photos.getMarketUploadServer';
				$saveMethod = 'photos.saveMarketPhoto';
				$keyReference = 'BX_ID';
				$keyPhotoVk = 'PHOTO_MAIN_VK_ID';
				$keyPhotoBx = 'PHOTO_MAIN_BX_ID';
				break;
			
			case 'PRODUCT_PHOTOS':
				$uploadServerMethod = 'photos.getMarketUploadServer';
				$saveMethod = 'photos.saveMarketPhoto';
				$keyReference = 'PHOTOS_BX_ID';
				$keyPhotoVk = 'PHOTOS_VK_ID';
				$keyPhotoBx = 'PHOTOS_BX_ID';
				break;
			
			case 'ALBUM_PHOTO':
				$uploadServerMethod = 'photos.getMarketAlbumUploadServer';
				$saveMethod = 'photos.saveMarketAlbumPhoto';
				$keyReference = 'SECTION_ID';
				$keyPhotoVk = 'PHOTO_VK_ID';
				$keyPhotoBx = 'PHOTO_BX_ID';
				break;
			
			default:
				throw new SystemException("Wrong photo upload type");
				break;
		}


//		PROCESSED
		foreach ($data as $item)
		{
//			check EXISTING photo
			if (!array_key_exists($keyPhotoBx, $item) || empty($item[$keyPhotoBx]))
				continue;

//			GET upload server by type
			$getServerParams = array("group_id" => str_replace("-", "", $vkGroupId));
			if ($uploadType == 'PRODUCT_MAIN_PHOTO')
				$getServerParams += self::setUploadServerMainPhotoParams($item[$keyPhotoBx]);
			$uploadServer = $this->api->run($uploadServerMethod, $getServerParams);
//			$uploadServer = Json::decode($uploadServer);
			$uploadServer = $uploadServer["upload_url"];

//			UPLOAD photo by http
			$responseHttp = ApiHelper::uploadPhotoHttp($item, $uploadServer, $uploadType, $timer);
			$responseHttp = Json::decode($responseHttp);

//			SAVE upload result
			$photoSaveParams = array(
				"group_id" => str_replace('-', '', $vkGroupId),
				"photo" => $responseHttp["photo"],
				"server" => $responseHttp["server"],
				"hash" => $responseHttp["hash"],
			);
			// for product photo we need more params
			if ($saveMethod == "photos.saveMarketPhoto")
			{
				if (isset($responseHttp["crop_hash"]) && $responseHttp["crop_hash"])
					$photoSaveParams["crop_hash"] = $responseHttp["crop_hash"];
				if (isset($responseHttp["crop_data"]) && $responseHttp["crop_data"])
					$photoSaveParams["crop_data"] = $responseHttp["crop_data"];
			}
			$responsePhotoSave = $this->api->run($saveMethod, $photoSaveParams);

//			RESULT
			$photoSaveResults[] = array(
				$keyReference => $item[$keyReference],
				$keyPhotoVk => $responsePhotoSave[0]["id"],
			);

//			todo: photo mapping. po odnomu, navernoe, ved timer
		}
		
		return $photoSaveResults;
	}
	
	
	/**
	 * Formatted params and run http-upload process
	 *
	 * @param $data
	 * @param $uploadServer
	 * @param $uploadType
	 * @param null $timer
	 * @return bool|string
	 * @throws SystemException
	 * @throws TimeIsOverException
	 */
	public static function uploadPhotoHttp($data, $uploadServer, $uploadType, Timer $timer = NULL)
	{
		switch ($uploadType)
		{
			case 'ALBUM_PHOTO':
				$postParams = array(
					"url" => $data["PHOTO_URL"],
					"filename" => IO\Path::getName($data["PHOTO_URL"]),
					"param_name" => 'file',
					"timer" => $timer,
				);
//				$result["album_vk_id"] = $data["album_vk_id"];
				break;
			
			case 'PRODUCT_MAIN_PHOTO':
				$postParams = array(
					"url" => $data["PHOTO_MAIN_URL"],
					"filename" => IO\Path::getName($data["PHOTO_MAIN_URL"]),
					"param_name" => 'photo',
					"timer" => $timer,
				);
//				$result["BX_ID"] = $data["BX_ID"];
				break;
			
			case 'PRODUCT_PHOTOS':
				$postParams = array(
					"url" => $data["PHOTOS_URL"],
					"filename" => IO\Path::getName($data["PHOTOS_URL"]),
					"param_name" => 'photo',
					"timer" => $timer,
				);
				break;
			
			default:
				throw new SystemException("Wrong upload type");
				break;
			
		}
		
		return self::uploadHttp($uploadServer, $postParams);
	}
	
	
	/**
	 * Execute http requst
	 *
	 * @param $uploadServer
	 * @param $params
	 * @return bool|string - result of http request
	 * @throws TimeIsOverException
	 */
	private function uploadHttp($uploadServer, $params)
	{
		$http = new HttpClient();
		$boundary = md5(rand() . time());

		$file = $http->get($params["url"]);
		
		$data = '';
		$data .= '--' . $boundary . "\r\n";
		$data .= 'Content-Disposition: form-data; name="' . $params["param_name"] . '"; filename="' . $params["filename"] . '"' . "\r\n";
		$data .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$data .= $file . "\r\n";
		$data .= '--' . $boundary . "--\r\n";
		
		$http->setHeader('Content-type', 'multipart/form-data; boundary=' . $boundary);
		$http->setHeader('Content-length', \Bitrix\Main\Text\BinaryString::getLength($data));
		
		$result = $http->post($uploadServer, $data);
		
//		check TIMER if set
		if (array_key_exists("timer", $params))
		{
			$timer = $params["timer"];
			if ($timer !== NULL && !$timer->check())
				throw new TimeIsOverException();
		}
		
		return $result;
	}
	
	
	/**
	 * Get list of VK albums from VK API
	 *
	 * @param $vkGroupId
	 * @param bool $flip
	 * @return array - list of VK albums
	 */
	public function getALbumsFromVk($vkGroupId, $flip = true)
	{
//		todo: so slow api request. Try cached this data or other acceleration techniques
		$albumsFromVk = $this->executer->executeMarketAlbumsGet(array(
			"owner_id" => $vkGroupId,
			"offset" => 0,
			"count" => Vk::MAX_ALBUMS,
		));
		$albumsFromVk = $albumsFromVk["items"];        //		get only items from response
		foreach ($albumsFromVk as &$item)    //		get only IDs from response
		{
			$item = $item["id"];
		}
		if ($flip)
			$albumsFromVk = array_flip($albumsFromVk);        // we need albumID as keys
		
		return $albumsFromVk;
	}
	
	
	/**
	 * Get list of VK products from VK API
	 *
	 * @param $vkGroupId
	 * @return array -  list of VK products
	 */
	public function getProductsFromVk($vkGroupId)
	{
		$productsFromVk = array();
		$prodGetStep = 0;
		while ($prodGetStep < Vk::MAX_PRODUCTS_IN_ALBUM)
		{
			$productsFromVk += $this->executer->executeMarketProductsGet(array(
					"owner_id" => $vkGroupId,
					"offset" => $prodGetStep,
					"step" => Vk::PRODUCTS_GET_STEP)
			);
			$prodGetStep += Vk::PRODUCTS_GET_STEP;
			// exit from loop, if we reach end of VK-products
			if ($productsFromVk["end_products"])
			{
				unset($productsFromVk["end_products"]);
				break;
			}
		}
		$productsFromVk = self::changeArrayMainKey($productsFromVk, 'id', 'vk_id');
		
		return $productsFromVk;
	}
	
	
	/**
	 * Check params for save products data.
	 * Check photos, description, vk-category
	 *
	 * @param $data
	 * @return array - prepared to save data array
	 */
	public static function prepareProductsDataToVk($data)
	{
		$result = array();
		foreach ($data as $item)
		{
//			check PHOTOS and formatted
			if (isset($item["PHOTOS"]) && is_array($item["PHOTOS"]))
			{
				$photosIds = array();
				foreach ($item["PHOTOS"] as $photo)
				{
					if (is_numeric($photo["PHOTOS_VK_ID"]))
						$photosIds[] = $photo["PHOTOS_VK_ID"];
				}
				
				if (!empty($photosIds))
					$item["PHOTOS"] = implode(",", $photosIds);
				else
					unset($item["PHOTOS"]);
			}
			
//			check VK_CATEGORY
			if (!(isset($item["category_vk_id"]) && is_int($item["category_vk_id"])))
				$item["category_vk_id"] = Vk::VERY_DEFAULT_VK_CATEGORY;    // we need some category
			
			$result[] = $item;
		}
		
		return $result;
	}
	
	
	/**
	 * Build params for http photo upload
	 *
	 * @param $photoId
	 * @return array
	 */
	private static function setUploadServerMainPhotoParams($photoId)
	{
		$result = array();
		$result["main_photo"] = 1;
		
		$photoParams = \CFile::GetFileArray($photoId);
		$w = $photoParams["WIDTH"];
		$h = $photoParams["HEIGHT"];
		
		if ($w >= $h)
		{
			$result["crop_x"] = ceil(($w + $h) / 2);
			$result["crop_y"] = 0;
			$result["crop_width"] = $h;
		}
		else
		{
			$result["crop_x"] = 0;
			$result["crop_y"] = ceil(($w + $h) / 2);
			$result["crop_width"] = $w;
		}
		
		return $result;
	}
	
	
	/**
	 * Get list of VK product categories from VK API
	 *
	 * @param int $count
	 * @param int $offset
	 * @return array - Get list of VK product categories. Return false if error
	 */
	public function getVkCategories($count = Vk::MAX_VK_CATEGORIES, $offset = 0)
	{
		$vkCats = $this->api->run('market.getCategories ', array("count" => $count, "offset" => $offset));
		
		if (!empty($vkCats))
			return $vkCats["items"];
		
		else
			return false;
	}
}