<?php

namespace AppBundle\Repository;

use AppBundle\Entity\RelatedEntityInterface;
use AppBundle\Field\Field;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * AdventureRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class AdventureRepository extends EntityRepository
{
    /**
     * Get all distinct values and their usage counts for a certain field. Will ignore NULL values
     *
     * @param string $field
     *
     * @return array Array of arrays containing the 'value' and 'count'
     */
    public function getFieldValueCounts(string $field): array
    {
        $qb = $this->createQueryBuilder('tbl');

        $field = 'tbl.' . $field;
        $results = $qb
            ->select($field)
            ->addSelect($qb->expr()->count($field))
            ->where($qb->expr()->isNotNull($field))
            ->groupBy($field)
            ->orderBy($qb->expr()->asc($field))
            ->getQuery()
            ->getArrayResult();

        return array_map(function ($result) {
            return [
                'value' => current($result),
                'count' => (int)$result[1],
            ];
        }, $results);
    }

    /**
     * Updates $field of all adventures where $field = $oldValue to $newValue
     *
     * @param Field $field
     * @param string $oldValue
     * @param string|null $newValue
     * @return int The number of affected adventures
     */
    public function updateField(Field $field, string $oldValue, string $newValue = null): int
    {
        $propertyAccessor = new PropertyAccessor();
        $em = $this->getEntityManager();
        if ($field->isRelatedEntity()) {
            $qb = $this->createQueryBuilder('a');
            $adventures = $qb
                ->join('a.' . $field->getName(), 'r')
                ->where($qb->expr()->eq('r.id', ':oldValue'))
                ->setParameter('oldValue', (int)$oldValue)
                ->getQuery()
                ->execute();
            foreach ($adventures as $adventure) {
                /** @var ArrayCollection|RelatedEntityInterface[] $currentRelatedEntities */
                $currentRelatedEntities = $propertyAccessor->getValue($adventure, $field->getName());
                if ($newValue === null) {
                    $newRelatedEntities = $currentRelatedEntities->filter(function (RelatedEntityInterface $relatedEntity) use ($oldValue) {
                        return $relatedEntity->getId() !== (int)$oldValue;
                    });
                } else {
                    $newRelatedEntity = $em->getReference($field->getRelatedEntityClass(), (int)$newValue);
                    /** @var ArrayCollection|RelatedEntityInterface[] $newRelatedDuplicatedEntities */
                    $newRelatedDuplicatedEntities = $currentRelatedEntities->map(function (RelatedEntityInterface $relatedEntity) use ($oldValue, $newRelatedEntity) {
                        if ($relatedEntity->getId() !== (int)$oldValue) {
                            return $relatedEntity;
                        } else {
                            return $newRelatedEntity;
                        }
                    })->toArray();

                    // Now we need to make sure to remove any duplicates
                    $newRelatedEntities = [];
                    foreach ($newRelatedDuplicatedEntities as $newRelatedDuplicatedEntity) {
                        $newRelatedEntities[$newRelatedDuplicatedEntity->getId()] = $newRelatedDuplicatedEntity;
                    }
                }
                $propertyAccessor->setValue($adventure, $field->getName(), $newRelatedEntities);
            }
        } else {
            $adventures = $this->findBy([$field->getName() => $oldValue]);
            foreach ($adventures as $adventure) {
                $propertyAccessor->setValue($adventure, $field->getName(), $newValue);
            }
        }

        $em->flush();

        return count($adventures);
    }

    /**
     * @return \Doctrine\ORM\Query
     */
    public function getWithMostUnresolvedChangeRequestsQuery()
    {
        $qb = $this->createQueryBuilder('a');

        return $qb
            ->join('a.changeRequests', 'c')
            ->where($qb->expr()->eq('c.resolved', $qb->expr()->literal(false)))
            ->select('a.title,a.slug')
            ->addSelect('COUNT(c.id) AS changeRequestCount')
            ->groupBy('a.id')
            ->orderBy($qb->expr()->desc('changeRequestCount'))
            ->getQuery();
    }
}
