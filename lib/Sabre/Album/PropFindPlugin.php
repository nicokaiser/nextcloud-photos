<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Photos\Sabre\Album;

use OC\Metadata\IMetadataManager;
use OCA\DAV\Connector\Sabre\FilesPlugin;
use OCA\Photos\Album\AlbumMapper;
use OCP\IConfig;
use OCP\IPreview;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Tree;
use OCP\Files\NotFoundException;

class PropFindPlugin extends ServerPlugin {
	public const FILE_NAME_PROPERTYNAME = '{http://nextcloud.org/ns}file-name';
	public const REALPATH_PROPERTYNAME = '{http://nextcloud.org/ns}realpath';
	public const FAVORITE_PROPERTYNAME = '{http://owncloud.org/ns}favorite';
	public const DATE_RANGE_PROPERTYNAME = '{http://nextcloud.org/ns}dateRange';
	public const LOCATION_PROPERTYNAME = '{http://nextcloud.org/ns}location';
	public const LAST_PHOTO_PROPERTYNAME = '{http://nextcloud.org/ns}last-photo';
	public const NBITEMS_PROPERTYNAME = '{http://nextcloud.org/ns}nbItems';

	public const TAG_FAVORITE = '_$!<Favorite>!$_';

	private IConfig $config;
	private IMetadataManager $metadataManager;
	private IPreview $previewManager;
	private bool $metadataEnabled;
	private ?Tree $tree;
	private AlbumMapper $albumMapper;

	public function __construct(
		IConfig $config,
		IMetadataManager $metadataManager,
		IPreview $previewManager,
		AlbumMapper $albumMapper
	) {
		$this->config = $config;
		$this->metadataManager = $metadataManager;
		$this->previewManager = $previewManager;
		$this->albumMapper = $albumMapper;
		$this->metadataEnabled = $this->config->getSystemValueBool('enable_file_metadata', true);
	}

	/**
	 * @return void
	 */
	public function initialize(Server $server) {
		$this->tree = $server->tree;
		$server->on('propFind', [$this, 'propFind']);
		$server->on('propPatch', [$this, 'handleUpdateProperties']);
	}

	public function propFind(PropFind $propFind, INode $node): void {
		if ($node instanceof AlbumPhoto) {
			// Checking if the node is trulely available and ignoring if not
			// Should be pre-emptively handled by the NodeDeletedEvent
			try {
				$fileInfo = $node->getFileInfo();
			} catch (NotFoundException $e) {
				return;
			}

			$propFind->handle(FilesPlugin::INTERNAL_FILEID_PROPERTYNAME, fn () => $node->getFile()->getFileId());
			$propFind->handle(FilesPlugin::GETETAG_PROPERTYNAME, fn () => $node->getETag());
			$propFind->handle(self::FILE_NAME_PROPERTYNAME, fn () => $node->getFile()->getName());
			$propFind->handle(self::REALPATH_PROPERTYNAME, fn () => $fileInfo->getPath());
			$propFind->handle(self::FAVORITE_PROPERTYNAME, fn () => $node->isFavorite() ? 1 : 0);

			$propFind->handle(FilesPlugin::HAS_PREVIEW_PROPERTYNAME, function () use ($fileInfo) {
				return json_encode($this->previewManager->isAvailable($fileInfo));
			});

			if ($this->metadataEnabled) {
				$propFind->handle(FilesPlugin::FILE_METADATA_SIZE, function () use ($node) {
					if (!str_starts_with($node->getFile()->getMimetype(), 'image')) {
						return json_encode((object)[]);
					}

					if ($node->getFile()->hasMetadata('size')) {
						$sizeMetadata = $node->getFile()->getMetadata('size');
					} else {
						$sizeMetadata = $this->metadataManager->fetchMetadataFor('size', [$node->getFile()->getFileId()])[$node->getFile()->getFileId()];
					}

					return json_encode((object)$sizeMetadata->getMetadata());
				});
			}
		}

		if ($node instanceof AlbumRoot) {
			$propFind->handle(self::LAST_PHOTO_PROPERTYNAME, fn () => $node->getAlbum()->getAlbum()->getLastAddedPhoto());
			$propFind->handle(self::NBITEMS_PROPERTYNAME, fn () => count($node->getChildren()));
			$propFind->handle(self::LOCATION_PROPERTYNAME, fn () => $node->getAlbum()->getAlbum()->getLocation());
			$propFind->handle(self::DATE_RANGE_PROPERTYNAME, fn () => json_encode($node->getDateRange()));

			// TODO detect dynamically which metadata groups are requested and
			// preload all of them and not just size
			if ($this->metadataEnabled && in_array(FilesPlugin::FILE_METADATA_SIZE, $propFind->getRequestedProperties(), true)) {
				$fileIds = $node->getAlbum()->getFileIds();

				$preloadedMetadata = $this->metadataManager->fetchMetadataFor('size', $fileIds);
				foreach ($node->getAlbum()->getFiles() as $file) {
					if (str_starts_with($file->getMimeType(), 'image')) {
						$file->setMetadata('size', $preloadedMetadata[$file->getFileId()]);
					}
				}
			}
		}
	}

	public function handleUpdateProperties($path, PropPatch $propPatch): void {
		$node = $this->tree->getNodeForPath($path);
		if ($node instanceof AlbumRoot) {
			$propPatch->handle(self::LOCATION_PROPERTYNAME, function ($location) use ($node) {
				$this->albumMapper->setLocation($node->getAlbum()->getAlbum()->getId(), $location);
				return true;
			});
		}
	}
}
