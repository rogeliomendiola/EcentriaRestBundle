<?php
/*
 * This file is part of the Ecentria software.
 *
 * (c) 2014, OpticsPlanet, Inc
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Ecentria\Libraries\CoreRestBundle\Services\CRUD;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Ecentria\Libraries\CoreRestBundle\Annotation\PropertyRestriction;
use JMS\Serializer\Serializer;

/**
 * CRUD Transformer
 *
 * @author Sergey Chernecov <sergey.chernecov@intexsys.lv>
 */
class CRUDTransformer
{
    /**
     * Entity manager
     *
     * @var EntityManager
     */
    private $entityManager;

    /**
     * Annotation reader
     *
     * @var AnnotationReader
     */
    private $annotationsReader;

    /**
     * Serializer
     *
     * @var Serializer
     */
    private $serializer;

    /**
     * Class metadata
     *
     * @var ClassMetadata
     */
    private $classMetadata;

    /**
     * Constructor
     *
     * @param EntityManager $entityManager
     * @param AnnotationReader $annotationsReader
     * @param Serializer $serializer
     */
    public function __construct(
        EntityManager $entityManager,
        AnnotationReader $annotationsReader,
        Serializer $serializer
    ) {
        $this->entityManager = $entityManager;
        $this->annotationsReader = $annotationsReader;
        $this->serializer = $serializer;
    }

    /**
     * Initializing class metadata
     *
     * @param string $className
     */
    public function initializeClassMetadata($className)
    {
        $this->classMetadata = $this->entityManager->getClassMetadata($className);
    }


    /**
     * Is property transform granted
     *
     * @param string $property
     * @param string $action
     * @return bool
     */
    public function isPropertyAccessible($property, $action)
    {
        $property = Inflector::camelize($property);
        if ($this->getClassMetadata()->hasAssociation(ucfirst($property))) {
            $property = ucfirst($property);
        }

        if (!$this->getClassMetadata()->hasField($property) && !$this->getClassMetadata()->hasAssociation($property)) {
            return false;
        }

        $propertyRestriction = $this->annotationsReader->getPropertyAnnotation(
            $this->getClassMetadata()->getReflectionProperty($property),
            PropertyRestriction::NAME
        );
        if ($propertyRestriction instanceof PropertyRestriction) {
            return $propertyRestriction->isGranted($action);
        }
        return true;
    }

    /**
     * Transform property value
     *
     * @param $property
     * @param $value
     * @param ArrayCollection $collection
     * @return object
     */
    public function transformPropertyValue($property, $value, ArrayCollection $collection = null)
    {
        if ($this->transformationNeeded($property, $value)) {
            $targetClass = $this->getClassMetadata()->getAssociationTargetClass(ucfirst($property));
            if (is_null($collection)) {
                if (is_array($value)) {
                    $deserializedValue = $this->serializer->deserialize(
                        json_encode($value),
                        $targetClass,
                        'json'
                    );
                    $value = $this->entityManager->find($targetClass, $deserializedValue->getId());
                    if (!$value) {
                        $value = $deserializedValue;
                    }
                } else {
                    $value = $this->entityManager->getReference($targetClass, $value);
                }
            } else {
                $object = $this->findByIdentifier($collection, $value);
                if (is_null($object)) {
                    if (is_array($value)) {
                        $deserializedObject = $this->serializer->deserialize(
                            json_encode($value),
                            $targetClass,
                            'json'
                        );
                        $object = $this->entityManager->find($targetClass, $deserializedObject->getId());
                        if (!$object) {
                            $object = $deserializedObject;
                        }
                    } else {
                        $object = $this->entityManager->getReference($targetClass, $value);
                    }
                }
                $value = $object;
            }
        }
        return $value;
    }

    /**
     * Getter for property setter
     *
     * @param $property
     * @return string
     */
    public function getPropertySetter($property)
    {
        return Inflector::camelize('set_' . $property);
    }

    /**
     * Getter for property getter
     *
     * @param $property
     * @return string
     */
    public function getPropertyGetter($property)
    {
        return Inflector::camelize('get_' . $property);
    }

    /**
     * Processing property value
     *
     * @param object $object
     * @param string $property
     * @param mixed $value
     * @param string $action
     * @param ArrayCollection|null $collection
     */
    public function processPropertyValue($object, $property, $value, $action, ArrayCollection $collection = null)
    {
        if (!$this->isPropertyAccessible($property, $action)) {
            return;
        }
        $value = $this->transformPropertyValue($property, $value, $collection);
        $method = $this->getPropertySetter($property);
        if (method_exists($object, $method)) {
            $object->$method($value);
        }
    }

    /**
     * @param ArrayCollection $collection
     * @param $value
     * @return null|object
     */
    private function findByIdentifier(ArrayCollection $collection, $value)
    {
        $object = null;
        foreach ($collection as $collectionItem) {
            $property = $this->getClassMetadata()->getSingleIdentifierFieldName();
            $method = $this->getPropertyGetter($property);
            if ($collectionItem->$method() === $value) {
                $object = $collectionItem;
            }
        }
        return $object;
    }

    /**
     * Transformation needed?
     *
     * @param $property
     * @param $value
     * @return bool
     */
    private function transformationNeeded($property, $value)
    {
        return is_null($value) ? false : $this->getClassMetadata()->hasAssociation(ucfirst($property));
    }

    /**
     * ClassMetadata getter
     *
     * @throws \Exception
     * @return ClassMetadata
     */
    private function getClassMetadata()
    {
        if (!$this->classMetadata instanceof ClassMetadata) {
            throw new \Exception('You forgot to call initializeClassMetadata method.');
        }
        return $this->classMetadata;
    }
}
