/**
 * Manage payload normalisers.
 *
 * Manage owns companies, branches and financial years, and its list endpoints
 * have grown several envelope shapes over the years. Insights stores their ids
 * and nothing else, so getting these wrong does not corrupt anything — it
 * silently opens the wrong company, which is worse.
 */

import { describe, expect, it } from 'vitest'
import {
  companyListTotal,
  formatFyLabel,
  parseBranchList,
  parseCompanyAddress,
  parseCompanyGstin,
  parseCompanyInfo,
  parseCompanyList,
  parseCompanyRow,
  pickFyForDate,
  pickLatestFy,
  resolveAcsType,
  toIsoDate,
} from './manageShapes'

describe('company list envelopes', () => {
  const rows = [
    { comp_id: 12, company_name: 'Sharma Trading Co', ownership: 'owner' },
    { comp_id: 15, company_name: 'Gupta Textiles', ownership: 'shared' },
  ]

  it('reads a bare array', () => {
    expect(parseCompanyList(rows).map((c) => c.cmpId)).toEqual([12, 15])
  })

  it('reads {data: [...]}', () => {
    expect(parseCompanyList({ data: rows }).map((c) => c.name)).toEqual(['Sharma Trading Co', 'Gupta Textiles'])
  })

  it('reads {data: {companies: [...]}}', () => {
    expect(parseCompanyList({ data: { companies: rows } })).toHaveLength(2)
  })

  it('reads a single company returned as an object', () => {
    expect(parseCompanyList({ data: { comp_id: 12, company_name: 'Sharma Trading Co' } })).toHaveLength(1)
  })

  it('de-duplicates by id, keeping payload order', () => {
    const list = parseCompanyList([...rows, { comp_id: 12, company_name: 'Sharma Trading Co (dup)' }])
    expect(list.map((c) => c.cmpId)).toEqual([12, 15])
    expect(list[0].name).toBe('Sharma Trading Co')
  })

  it('returns nothing for shapes it does not recognise rather than guessing', () => {
    expect(parseCompanyList(null)).toEqual([])
    expect(parseCompanyList('nope')).toEqual([])
    expect(parseCompanyList({ result: 'ok' })).toEqual([])
  })

  it('drops rows with no usable id', () => {
    expect(parseCompanyList([{ company_name: 'No id' }, { comp_id: 0, company_name: 'Zero' }])).toEqual([])
  })

  it('prefers the reported total, and falls back to the rows in hand', () => {
    const list = parseCompanyList(rows)
    expect(companyListTotal({ data: rows, meta: { total: 40 } }, list)).toBe(40)
    expect(companyListTotal({ data: rows }, list)).toBe(2)
  })
})

describe('access type', () => {
  it('trusts acs_type first', () => {
    expect(resolveAcsType({ acs_type: 1, ownership: 'shared' })).toBe(1)
    expect(resolveAcsType({ acs_type: 0, ownership: 'owner' })).toBe(0)
  })

  it('falls back to the ownership label, then is_creator', () => {
    expect(resolveAcsType({ ownership: 'owner' })).toBe(1)
    expect(resolveAcsType({ ownership: 'delegated' })).toBe(0)
    expect(resolveAcsType({ is_creator: '1' })).toBe(1)
  })

  it('says unknown rather than assuming ownership', () => {
    // Guessing "owner" here would show someone a company they may not own.
    expect(resolveAcsType({ comp_id: 12 })).toBeNull()
  })
})

describe('dates and financial years', () => {
  it('parses a date without a timezone shift', () => {
    // new Date('2026-04-01').toISOString() in IST is what turns 1 April into
    // 31 March, which puts a voucher in the wrong financial year.
    expect(toIsoDate('2026-04-01')).toBe('2026-04-01')
    expect(toIsoDate('2026-04-01 00:00:00')).toBe('2026-04-01')
    expect(toIsoDate('')).toBe('')
    expect(toIsoDate('not a date')).toBe('')
  })

  it('labels an Indian financial year across the year boundary', () => {
    expect(formatFyLabel('2025-04-01', '2026-03-31')).toBe('FY 2025-26')
    expect(formatFyLabel('2025-01-01', '2025-12-31')).toBe('FY 2025')
    expect(formatFyLabel('', '', 'FY #4')).toBe('FY #4')
  })

  it('sorts financial years latest first', () => {
    const info = parseCompanyInfo({
      data: {
        cmp_id: 12,
        comp_name: 'Sharma Trading Co',
        fy_list: [
          { fy_id: 1, fy_start: '2024-04-01', fy_end: '2025-03-31' },
          { fy_id: 3, fy_start: '2026-04-01', fy_end: '2027-03-31' },
          { fy_id: 2, fy_start: '2025-04-01', fy_end: '2026-03-31' },
        ],
      },
    })

    expect(info.fyList.map((fy) => fy.fyId)).toEqual([3, 2, 1])
    expect(info.fyList[0].label).toBe('FY 2026-27')
    expect(pickLatestFy(info.fyList)?.fyId).toBe(3)
  })

  it('picks the year a date falls inside, else the latest', () => {
    const list = parseCompanyInfo({
      data: {
        fy_list: [
          { fy_id: 1, fy_start: '2024-04-01', fy_end: '2025-03-31' },
          { fy_id: 2, fy_start: '2025-04-01', fy_end: '2026-03-31' },
        ],
      },
    }).fyList

    expect(pickFyForDate(list, '2025-06-30')?.fyId).toBe(2)
    expect(pickFyForDate(list, '2024-12-31')?.fyId).toBe(1)
    expect(pickFyForDate(list, '2030-01-01')?.fyId).toBe(2)
    expect(pickFyForDate([], '2025-06-30')).toBeNull()
  })
})

describe('branches', () => {
  it('reads the several shapes and marks the head office', () => {
    const branches = parseBranchList({
      data: [
        { bo_id: 1, hobo_name: 'Head Office', mark_ho: '1' },
        { branch_id: 2, branch_name: 'Pune Depot' },
      ],
    })

    expect(branches).toEqual([
      { boId: 1, name: 'Head Office', isHeadOffice: true },
      { boId: 2, name: 'Pune Depot', isHeadOffice: false },
    ])
  })

  it('de-duplicates by id', () => {
    expect(parseBranchList([{ bo_id: 1, bo_name: 'A' }, { bo_id: 1, bo_name: 'A again' }])).toHaveLength(1)
  })
})

describe('letterhead fields', () => {
  it('reads a free-text address block a line at a time', () => {
    expect(parseCompanyAddress({ data: { ro_address: '12 MG Road\nSuite 4\n\nPune 411001' } })).toEqual([
      '12 MG Road',
      'Suite 4',
      'Pune 411001',
    ])
  })

  it('reads a structured address object', () => {
    const lines = parseCompanyAddress({
      data: { address: { addr1: '12 MG Road', city: 'Pune', state_name: 'Maharashtra', pincode: '411001' } },
    })

    expect(lines[0]).toContain('12 MG Road')
    expect(lines.join(' ')).toContain('Pune')
    expect(lines.join(' ')).toContain('411001')
  })

  it('ignores a bare master id where a place name belongs', () => {
    // `state: 27` is Manage's id for Maharashtra, not somewhere to post a letter.
    const lines = parseCompanyAddress({ data: { addr1: '12 MG Road', city: 'Pune', state: 27, pincode: '411001' } })
    expect(lines.join(' ')).not.toMatch(/\b27\b/)
  })

  it('reads the GSTIN under any of its spellings, and reports absence as empty', () => {
    expect(parseCompanyGstin({ data: { gst_no: '27AAAPL1234C1ZV' } })).toBe('27AAAPL1234C1ZV')
    expect(parseCompanyGstin({ data: { company_gstin: '27AAAPL1234C1ZV' } })).toBe('27AAAPL1234C1ZV')
    expect(parseCompanyGstin({ data: {} })).toBe('')
  })
})

describe('company info', () => {
  it('returns an empty shape rather than throwing on rubbish', () => {
    const info = parseCompanyInfo(null)
    expect(info).toEqual({
      cmpId: null,
      name: '',
      fyList: [],
      branches: [],
      hoId: null,
      addressLines: [],
      gstin: '',
    })
  })

  it('keeps the name Manage gave, without inventing one', () => {
    expect(parseCompanyRow({ comp_id: 12 })?.name).toBe('Company #12')
    expect(parseCompanyRow({ comp_id: 12, company_name: 'Sharma Trading Co' })?.name).toBe('Sharma Trading Co')
  })
})
