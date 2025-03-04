<?php
namespace Concrete\Tests\Controller\SinglePage;

use Concrete\Core\Attribute\Key\Category;
use Concrete\Core\File\Filesystem;
use Concrete\Core\File\StorageLocation\StorageLocation;
use Concrete\Core\File\StorageLocation\Type\Type;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\File\File;
use Concrete\Core\Http\Request;
use Concrete\Core\Http\ServerInterface;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Permission\Access\Access;
use Concrete\Core\Permission\Access\Entity\Type as AccessEntityType;
use Concrete\Core\Permission\Access\Entity\GroupEntity as GroupPermissionAccessEntity;
use Concrete\Core\Permission\Category as PermissionCategory;
use Concrete\Core\Permission\Key\Key as PermissionKey;
use Concrete\TestHelpers\Page\PageTestCase;
use Core;
use Doctrine\ORM\EntityManagerInterface;
use Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DownloadFileTest extends PageTestCase
{
    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        // Files
        $this->tables[] = 'FileImageThumbnailPaths';

        // Users & permissions
        $this->tables[] = 'UserGroups';
        $this->tables[] = 'Groups';
        $this->tables[] = 'TreeTypes';
        $this->tables[] = 'TreeNodes';
        $this->tables[] = 'TreeNodePermissionAssignments';
        $this->tables[] = 'AreaPermissionAssignments';
        $this->tables[] = 'FilePermissionAssignments';
        $this->tables[] = 'PermissionAccess';
        $this->tables[] = 'PermissionAccessEntities';
        $this->tables[] = 'PermissionAccessEntityGroups';
        $this->tables[] = 'PermissionAccessList';
        $this->tables[] = 'PermissionKeyCategories';
        $this->tables[] = 'PermissionKeys';
        $this->tables[] = 'TreeNodeTypes';
        $this->tables[] = 'Trees';
        $this->tables[] = 'TreeGroupNodes';
        $this->tables[] = 'TreeFileFolderNodes';
        $this->tables[] = 'TreeFileNodes';

        // Blocks
        $this->tables[] = 'Blocks';

        // Stacks
        $this->tables[] = 'Stacks';

        // Files
        $this->metadatas[] = 'Concrete\Core\Entity\File\DownloadStatistics';
        $this->metadatas[] = 'Concrete\Core\Entity\File\File';
        $this->metadatas[] = 'Concrete\Core\Entity\File\Version';

        // Users
        $this->metadatas[] = 'Concrete\Core\Entity\User\User';
        $this->metadatas[] = 'Concrete\Core\Entity\Attribute\Category';
        $this->metadatas[] = 'Concrete\Core\Entity\Attribute\Key\Key';
        $this->metadatas[] = 'Concrete\Core\Entity\Attribute\Key\UserKey';
        $this->metadatas[] = 'Concrete\Core\Entity\Attribute\Value\UserValue';

        // Blocks
        $this->metadatas[] = 'Concrete\Core\Entity\Block\BlockType\BlockType';

        // Files
        $this->metadatas[] = 'Concrete\Core\Entity\File\DownloadStatistics';
        $this->metadatas[] = 'Concrete\Core\Entity\File\File';
        $this->metadatas[] = 'Concrete\Core\Entity\File\Version';
        $this->metadatas[] = 'Concrete\Core\Entity\Attribute\Key\FileKey';
        $this->metadatas[] = 'Concrete\Core\Entity\Attribute\Value\FileValue';
        $this->metadatas[] = 'Concrete\Core\Entity\File\Image\Thumbnail\Type\Type';
        $this->metadatas[] = 'Concrete\Core\Entity\File\StorageLocation\Type\Type';
        $this->metadatas[] = 'Concrete\Core\Entity\File\StorageLocation\StorageLocation';
    }

    public static function setUpBeforeClass():void
    {
        parent::setUpBeforeClass();

        Category::add('user');
        Category::add('file');
        Category::add('collection');
        AccessEntityType::add('page_owner', 'Page Owner');
        AccessEntityType::add('group', 'Group');
        PermissionCategory::add('page');
        PermissionKey::add('page', 'view_page', 'View Page', '', 0, 0);
        PermissionKey::add('page', 'view_page_versions', 'View Page Versions', '', 0, 0);
        PermissionKey::add('page', 'edit_page_contents', 'Edit Page Contents', '', 0, 0);
        PermissionKey::add('page', 'edit_page_properties', 'Edit Page Properties', '', 0, 0);
        PermissionCategory::add('file');
        PermissionKey::add('file', 'view_file', 'View File', '', 0, 0);
        PermissionCategory::add('file_folder');
        PermissionKey::add('file_folder', 'view_file_folder_file', 'View files within folder', '', 0, 0);

        $page = SinglePage::add('/download_file');

        $guest = Group::add('Guest', '');

        $page->setPermissionsToManualOverride();

        $pk = PermissionKey::getByHandle('view_page');
        $pk->setPermissionObject($page);
        $pt = $pk->getPermissionAssignmentObject();
        $pt->clearPermissionAssignment();
        $pa = Access::create($pk);
        $pa->addListItem(GroupPermissionAccessEntity::getOrCreate($guest));
        $pt->assignPermissionAccess($pa);
    }

    public function setUp(): void
    {
        parent::setUp();

        $filesystem = new Filesystem();
        $filesystem->create();

        $folder = $filesystem->getRootFolder();
        $folder->assignPermissions(Group::getByName('Guest'), ['view_file_folder_file']);

        $this->cleanup();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->cleanup();
    }

    public function testInvalidRelatedPageId()
    {
        mkdir($this->getStorageDirectory());
        $this->getStorageLocation();

        $importer = Core::make(FileImporter::class);
        $prefix = $importer->generatePrefix();
        $version = File::add('test.jpg', $prefix);
        $file = $version->getFile();

        $url = sprintf(
          'http://www.dummyco.com/download_file/view/%s/%s',
          $file->getFileUUID(), // $file->getFileID(),
          '1&sa=u389&ved=2a29gw2xqd1kf4maq0qgh0taw5agwa4awaga'
        );

        $request = Request::create($url, 'GET', []);

        $server = Core::make(ServerInterface::class);

        // The controller "sends" the request, i.e. prints it to the current
        // PHP thread STDOUT which is why we want to hide this from the test
        // output.
        ob_start();
        $response = $server->handleRequest($request);
        $contents = ob_get_contents();
        ob_end_clean();

        $this->assertEquals($response->getStatusCode(), 307);

        $expectedUrl = sprintf(
          'http://www.dummyco.com/application/files/%s/%s',
          implode('/', str_split($version->getPrefix(), 4)),
          $version->getFileName()
        );
        $this->assertEquals($response->headers->get('Location'), $expectedUrl);
    }

    protected function getStorageDirectory()
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', __DIR__) . '/files';
    }

    protected function cleanup()
    {
        if (is_dir($this->getStorageDirectory())) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->getStorageDirectory(), RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($this->getStorageDirectory());
        }
    }

    /**
     * @return \Concrete\Core\Entity\File\StorageLocation\StorageLocation
     */
    protected function getStorageLocation()
    {
        $type = Type::add('local', t('Local Storage'));
        $configuration = $type->getConfigurationObject();
        $configuration->setRootPath($this->getStorageDirectory());
        $configuration->setWebRootRelativePath('/application/files');

        return StorageLocation::add($configuration, 'Default', true);
    }
}
