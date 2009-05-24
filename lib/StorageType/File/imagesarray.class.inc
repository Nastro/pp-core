<?php
/**
 * ������ ��������
 * �������� � site/htdocs/ai/���_������/��_�������/���_����/���_�����.�����������_���
 * ��������: site/htdocs/ai/news/123034/images/1.jpg
 *           site/htdocs/ai/news/123034/images/girl.png
 *           site/htdocs/ai/news/123034/images/dos.jpg
 *
 * displayType IMAGESARRAY
 * storageType imagesarray
 */
class PXStorageTypeImagesarray extends PXStorageTypeFilesarray {
	function proceedFile($field, $object, &$param, $allowed=ENUM_ALLOWED_IMAGETYPES) {
		$param['allowed'] = unserialize($allowed);
		return parent::proceedFile($field, $object, $param);
	}

	function normalize($field, $object, &$param) {
		$imagesArray = array();
		$basedir = '/ai/'.$param['format'].'/'.$object['id'].'/'.$field->name.'/';

		$d = new NLDir(BASEPATH.'/site/htdocs'.$basedir);

		if ($d->Exist()) {
			foreach ($d->getFileNames() as $entry) {
				$diskPath = BASEPATH.'/site/htdocs'.$basedir.$entry;
				$httpPath = $basedir.$entry;
				list($width, $height, ) = getimagesize($diskPath);

				$imagesArray[$entry] = array(
					'path'   => isset($httpPath) ? $httpPath : NULL,
					'width'  => isset($width)    ? $width    : NULL,
					'height' => isset($height)   ? $height   : NULL,
					'entry'  => $entry
				);

				unset($httpPath);
				unset($width);
				unset($height);
			}
		}

		return $imagesArray;
	}
}
?>