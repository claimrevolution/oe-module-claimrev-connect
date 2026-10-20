<?php

/**
 * Pulls the patient's primary care provider out of a ClaimRev eligibility
 * result.
 *
 * The PCP is not a top-level field. It lives in the parsed 271, as a related
 * entity (2120 loop) with entity identifier code P3, under the benefits of the
 * subscriber or, when the patient is a dependent, the dependent. Payers often
 * repeat it under several benefits, so results are de-duplicated by NPI.
 *
 * The top-level eligibility relatedEntities list is deliberately ignored: it is
 * a name-only vendor summary with no NPI or entity code.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

class PrimaryCareProvider
{
    private const ENTITY_CODE_PCP = 'P3';
    private const ID_QUALIFIER_NPI = 'XX';
    private const CONTACT_TYPE_PHONE = 'TE';

    /**
     * @return list<array{name: string, npi: string, address: string, phone: string}>
     */
    public static function fromEligibility(mixed $eligibility): array
    {
        if (!is_object($eligibility) || !property_exists($eligibility, 'mapped271') || !is_object($eligibility->mapped271)) {
            return [];
        }
        $mapped271 = $eligibility->mapped271;

        $providers = [];
        foreach (['dependent', 'subscriber'] as $level) {
            $member = property_exists($mapped271, $level) ? $mapped271->$level : null;
            if (!is_object($member) || !property_exists($member, 'benefits') || !is_iterable($member->benefits)) {
                continue;
            }
            foreach ($member->benefits as $benefit) {
                if (!is_object($benefit) || !property_exists($benefit, 'relatedEntities') || !is_iterable($benefit->relatedEntities)) {
                    continue;
                }
                foreach ($benefit->relatedEntities as $entity) {
                    if (!is_object($entity) || self::prop($entity, 'entityIdentifierCode') !== self::ENTITY_CODE_PCP) {
                        continue;
                    }
                    $pcp = self::describe($entity);
                    if ($pcp['name'] === '' && $pcp['npi'] === '') {
                        continue;
                    }
                    // Same NPI = same provider. Without an NPI, fall back to the name.
                    $key = $pcp['npi'] !== '' ? 'npi:' . $pcp['npi'] : 'name:' . strtolower($pcp['name']);
                    $providers[$key] ??= $pcp;
                }
            }
        }

        return array_values($providers);
    }

    /**
     * @return array{name: string, npi: string, address: string, phone: string}
     */
    private static function describe(object $entity): array
    {
        return [
            'name' => self::name($entity),
            'npi' => self::prop($entity, 'idQualifier') === self::ID_QUALIFIER_NPI ? self::prop($entity, 'identifier') : '',
            'address' => self::address($entity),
            'phone' => self::phone($entity),
        ];
    }

    private static function name(object $entity): string
    {
        $last = self::prop($entity, 'lastOrganizationName');
        $first = self::prop($entity, 'firstName');
        $middle = self::prop($entity, 'middleName');

        return trim(implode(' ', array_filter([$first, $middle, $last], static fn(string $part): bool => $part !== '')));
    }

    private static function address(object $entity): string
    {
        $address = property_exists($entity, 'address') ? $entity->address : null;
        if (!is_object($address)) {
            return '';
        }

        $street = implode(' ', array_filter(
            [self::prop($address, 'address1'), self::prop($address, 'address2')],
            static fn(string $part): bool => $part !== ''
        ));
        $stateZip = trim(self::prop($address, 'state') . ' ' . self::formatZip(self::prop($address, 'zip')));

        return implode(', ', array_filter(
            [$street, self::prop($address, 'city'), $stateZip],
            static fn(string $part): bool => $part !== ''
        ));
    }

    private static function phone(object $entity): string
    {
        $contacts = property_exists($entity, 'contacts') ? $entity->contacts : null;
        if (!is_iterable($contacts)) {
            return '';
        }
        foreach ($contacts as $contact) {
            if (!is_object($contact) || !property_exists($contact, 'contactMethods') || !is_iterable($contact->contactMethods)) {
                continue;
            }
            foreach ($contact->contactMethods as $method) {
                if (is_object($method) && self::prop($method, 'contactType') === self::CONTACT_TYPE_PHONE) {
                    $value = self::prop($method, 'contactValue');
                    if ($value !== '') {
                        return self::formatPhone($value);
                    }
                }
            }
        }
        return '';
    }

    public static function formatZip(string $zip): string
    {
        return preg_match('/^\d{9}$/', $zip) === 1 ? substr($zip, 0, 5) . '-' . substr($zip, 5) : $zip;
    }

    public static function formatPhone(string $phone): string
    {
        return preg_match('/^\d{10}$/', $phone) === 1
            ? '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6)
            : $phone;
    }

    private static function prop(object $obj, string $name): string
    {
        return property_exists($obj, $name) ? trim(TypeCoerce::asString($obj->$name)) : '';
    }
}
