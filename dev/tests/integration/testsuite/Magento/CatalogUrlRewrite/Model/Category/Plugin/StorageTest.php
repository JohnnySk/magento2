<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Model\Category\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as ProductEntity;
use Magento\Catalog\Model\Product\Media\ConfigInterface;
use Magento\Framework\App\Bootstrap as AppBootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\ObjectManagerInterface;
use Magento\ImportExport\Model\Import;
use Magento\CatalogImportExport\Model\Import\ProductFactory;
use Magento\ImportExport\Model\Import\Source\Csv;
use Magento\ImportExport\Model\Import\Source\CsvFactory;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Checks that product import with same images can be successfully done
 *
 * @magentoAppArea adminhtml
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StorageTest extends TestCase
{
    /** @var ObjectManagerInterface */
    private $objectManager;

    /** @var Filesystem */
    private $fileSystem;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var File */
    private $fileDriver;

    /** @var Import */
    private $import;

    /** @var ConfigInterface */
    private $mediaConfig;

    /** @var array */
    private $appParams;

    /** @var array */
    private $createdProductsSkus = [];

    /** @var array */
    private $filesToRemove = [];

    /** @var CsvFactory */
    private $csvFactory;

    /** @var Data */
    private $importDataResource;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = Bootstrap::getObjectManager();
        $this->fileSystem = $this->objectManager->get(Filesystem::class);
        $this->fileDriver = $this->objectManager->get(File::class);
        $this->mediaConfig = $this->objectManager->get(ConfigInterface::class);
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $this->productRepository->cleanCache();
        $this->import = $this->objectManager->get(ProductFactory::class)->create();
        $this->csvFactory = $this->objectManager->get(CsvFactory::class);
        $this->importDataResource = $this->objectManager->get(Data::class);
        $this->appParams = Bootstrap::getInstance()->getBootstrap()->getApplication()
            ->getInitParams()[AppBootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS];
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->removeFiles();
        $this->removeProducts();
        $this->importDataResource->cleanBunches();

        parent::tearDown();
    }

    /**
     * @return void
     * @magentoDataFixture Magento/CatalogUrlRewrite/_files/issue34210_structure.php
     */
    public function testIssue34210(): void
    {
        $source = $this->prepareFile('issue34210.csv');
        $this->updateUploader();
        $errors = $this->import->setParameters([
            'behavior' => Import::BEHAVIOR_ADD_UPDATE,
            'entity' => ProductEntity::ENTITY,
        ])
            ->setSource($source)->validateData();
        $this->assertEmpty($errors->getAllErrors());

        /**
         * if the issue is fixed, we get
         * Zend_Db_Statement_Exception : SQLSTATE[HY000]: General error: 1412 Table definition has changed, please retry transaction, query was: SELECT `catalog_category_product_index_store2`.`category_id`, `catalog_category_product_index_store2`.`product_id`, `catalog_category_product_index_store2`.`position`, `catalog_category_product_index_store2`.`store_id` FROM `catalog_category_product_index_store2` WHERE (store_id = '2') AND (product_id IN (4, 9))
         * in the next line
         */
        $result = $this->import->importData();
        $this->assertTrue($result);
    }

    /**
     * Check product images
     *
     * @param string $expectedImagePath
     * @param array $productSkus
     * @return void
     */
    private function checkProductsImages(string $expectedImagePath, array $productSkus): void
    {
        foreach ($productSkus as $productSku) {
            $product = $this->productRepository->get($productSku);
            $productImageItem = $product->getMediaGalleryImages()->getFirstItem();
            $productImageFile = $productImageItem->getFile();
            $productImagePath = $productImageItem->getPath();
            $this->filesToRemove[] = $productImagePath;
            $this->assertEquals($expectedImagePath, $productImageFile);
            $this->assertNotEmpty($productImagePath);
            $this->assertTrue($this->fileDriver->isExists($productImagePath));
        }
    }

    /**
     * Remove created files
     *
     * @return void
     */
    private function removeFiles(): void
    {
        foreach ($this->filesToRemove as $file) {
            if ($this->fileDriver->isExists($file)) {
                $this->fileDriver->deleteFile($file);
            }
        }
    }

    /**
     * Remove created products
     *
     * @return void
     */
    private function removeProducts(): void
    {
        foreach ($this->createdProductsSkus as $sku) {
            try {
                $this->productRepository->deleteById($sku);
            } catch (NoSuchEntityException $e) {
                //already removed
            }
        }
    }

    /**
     * Prepare file
     *
     * @param string $fileName
     * @return Csv
     */
    private function prepareFile(string $fileName): Csv
    {
        $tmpDirectory = $this->fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $fixtureDir = realpath(__DIR__ . '/../../../_files');
        $filePath = $tmpDirectory->getAbsolutePath($fileName);
        $this->filesToRemove[] = $filePath;
        $tmpDirectory->getDriver()->copy($fixtureDir . DIRECTORY_SEPARATOR . $fileName, $filePath);
        $source = $this->csvFactory->create(
            [
                'file' => $fileName,
                'directory' => $tmpDirectory
            ]
        );

        return $source;
    }

    /**
     * Update upload to use sandbox folders
     *
     * @return void
     */
    private function updateUploader(): void
    {
        $uploader = $this->import->getUploader();
        $rootDirectory = $this->fileSystem->getDirectoryWrite(DirectoryList::ROOT);
        $destDir = $rootDirectory->getRelativePath(
            $this->appParams[DirectoryList::MEDIA][DirectoryList::PATH]
            . DIRECTORY_SEPARATOR . $this->mediaConfig->getBaseMediaPath()
        );
        $tmpDir = $rootDirectory->getRelativePath(
            $this->appParams[DirectoryList::MEDIA][DirectoryList::PATH]
        );
        $rootDirectory->create($destDir);
        $rootDirectory->create($tmpDir);
        $uploader->setDestDir($destDir);
        $uploader->setTmpDir($tmpDir);
    }

    /**
     * Move images to appropriate folder
     *
     * @param string $fileName
     * @return void
     */
    private function moveImages(string $fileName): void
    {
        $rootDirectory = $this->fileSystem->getDirectoryWrite(DirectoryList::ROOT);
        $tmpDir = $rootDirectory->getRelativePath(
            $this->appParams[DirectoryList::MEDIA][DirectoryList::PATH]
        );
        $fixtureDir = realpath(__DIR__ . '/../../_files');
        $tmpFilePath = $rootDirectory->getAbsolutePath($tmpDir . DIRECTORY_SEPARATOR . $fileName);
        $this->fileDriver->createDirectory($tmpDir);
        $rootDirectory->getDriver()->copy(
            $fixtureDir . DIRECTORY_SEPARATOR . $fileName,
            $tmpFilePath
        );
        $this->filesToRemove[] = $tmpFilePath;
    }
}
