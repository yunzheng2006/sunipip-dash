// Spark 返回的 3 字母国家代码映射表（ISO 3166-1 alpha-3）
// cn: 中文名, flag: 国旗emoji, continent: 所属大洲, iso2: 2字母代码

export const COUNTRIES = {
  // 北美洲
  USA: { cn: '美国', flag: '🇺🇸', continent: '北美洲', iso2: 'US' },
  CAN: { cn: '加拿大', flag: '🇨🇦', continent: '北美洲', iso2: 'CA' },
  MEX: { cn: '墨西哥', flag: '🇲🇽', continent: '北美洲', iso2: 'MX' },

  // 亚洲
  CHN: { cn: '中国/香港', flag: '🇨🇳', continent: '亚洲', iso2: 'CN' },
  HKG: { cn: '香港', flag: '🇭🇰', continent: '亚洲', iso2: 'HK' },
  TWN: { cn: '台湾', flag: '🇹🇼', continent: '亚洲', iso2: 'TW' },
  JPN: { cn: '日本', flag: '🇯🇵', continent: '亚洲', iso2: 'JP' },
  KOR: { cn: '韩国', flag: '🇰🇷', continent: '亚洲', iso2: 'KR' },
  SGP: { cn: '新加坡', flag: '🇸🇬', continent: '亚洲', iso2: 'SG' },
  MYS: { cn: '马来西亚', flag: '🇲🇾', continent: '亚洲', iso2: 'MY' },
  IDN: { cn: '印尼', flag: '🇮🇩', continent: '亚洲', iso2: 'ID' },
  THA: { cn: '泰国', flag: '🇹🇭', continent: '亚洲', iso2: 'TH' },
  VNM: { cn: '越南', flag: '🇻🇳', continent: '亚洲', iso2: 'VN' },
  PHL: { cn: '菲律宾', flag: '🇵🇭', continent: '亚洲', iso2: 'PH' },
  IND: { cn: '印度', flag: '🇮🇳', continent: '亚洲', iso2: 'IN' },
  PAK: { cn: '巴基斯坦', flag: '🇵🇰', continent: '亚洲', iso2: 'PK' },
  BGD: { cn: '孟加拉', flag: '🇧🇩', continent: '亚洲', iso2: 'BD' },
  LKA: { cn: '斯里兰卡', flag: '🇱🇰', continent: '亚洲', iso2: 'LK' },
  KAZ: { cn: '哈萨克斯坦', flag: '🇰🇿', continent: '亚洲', iso2: 'KZ' },
  MMR: { cn: '缅甸', flag: '🇲🇲', continent: '亚洲', iso2: 'MM' },
  KHM: { cn: '柬埔寨', flag: '🇰🇭', continent: '亚洲', iso2: 'KH' },
  LAO: { cn: '老挝', flag: '🇱🇦', continent: '亚洲', iso2: 'LA' },
  NPL: { cn: '尼泊尔', flag: '🇳🇵', continent: '亚洲', iso2: 'NP' },

  // 中东
  ARE: { cn: '阿联酋', flag: '🇦🇪', continent: '中东', iso2: 'AE' },
  SAU: { cn: '沙特阿拉伯', flag: '🇸🇦', continent: '中东', iso2: 'SA' },
  TUR: { cn: '土耳其', flag: '🇹🇷', continent: '中东', iso2: 'TR' },
  ISR: { cn: '以色列', flag: '🇮🇱', continent: '中东', iso2: 'IL' },
  IRN: { cn: '伊朗', flag: '🇮🇷', continent: '中东', iso2: 'IR' },
  IRQ: { cn: '伊拉克', flag: '🇮🇶', continent: '中东', iso2: 'IQ' },
  JOR: { cn: '约旦', flag: '🇯🇴', continent: '中东', iso2: 'JO' },
  LBN: { cn: '黎巴嫩', flag: '🇱🇧', continent: '中东', iso2: 'LB' },
  KWT: { cn: '科威特', flag: '🇰🇼', continent: '中东', iso2: 'KW' },
  QAT: { cn: '卡塔尔', flag: '🇶🇦', continent: '中东', iso2: 'QA' },
  BHR: { cn: '巴林', flag: '🇧🇭', continent: '中东', iso2: 'BH' },
  OMN: { cn: '阿曼', flag: '🇴🇲', continent: '中东', iso2: 'OM' },

  // 欧洲
  GBR: { cn: '英国', flag: '🇬🇧', continent: '欧洲', iso2: 'GB' },
  DEU: { cn: '德国', flag: '🇩🇪', continent: '欧洲', iso2: 'DE' },
  FRA: { cn: '法国', flag: '🇫🇷', continent: '欧洲', iso2: 'FR' },
  ITA: { cn: '意大利', flag: '🇮🇹', continent: '欧洲', iso2: 'IT' },
  ESP: { cn: '西班牙', flag: '🇪🇸', continent: '欧洲', iso2: 'ES' },
  PRT: { cn: '葡萄牙', flag: '🇵🇹', continent: '欧洲', iso2: 'PT' },
  NLD: { cn: '荷兰', flag: '🇳🇱', continent: '欧洲', iso2: 'NL' },
  BEL: { cn: '比利时', flag: '🇧🇪', continent: '欧洲', iso2: 'BE' },
  CHE: { cn: '瑞士', flag: '🇨🇭', continent: '欧洲', iso2: 'CH' },
  AUT: { cn: '奥地利', flag: '🇦🇹', continent: '欧洲', iso2: 'AT' },
  SWE: { cn: '瑞典', flag: '🇸🇪', continent: '欧洲', iso2: 'SE' },
  NOR: { cn: '挪威', flag: '🇳🇴', continent: '欧洲', iso2: 'NO' },
  FIN: { cn: '芬兰', flag: '🇫🇮', continent: '欧洲', iso2: 'FI' },
  DNK: { cn: '丹麦', flag: '🇩🇰', continent: '欧洲', iso2: 'DK' },
  IRL: { cn: '爱尔兰', flag: '🇮🇪', continent: '欧洲', iso2: 'IE' },
  POL: { cn: '波兰', flag: '🇵🇱', continent: '欧洲', iso2: 'PL' },
  RUS: { cn: '俄罗斯', flag: '🇷🇺', continent: '欧洲', iso2: 'RU' },
  UKR: { cn: '乌克兰', flag: '🇺🇦', continent: '欧洲', iso2: 'UA' },
  GRC: { cn: '希腊', flag: '🇬🇷', continent: '欧洲', iso2: 'GR' },
  CZE: { cn: '捷克', flag: '🇨🇿', continent: '欧洲', iso2: 'CZ' },
  ROU: { cn: '罗马尼亚', flag: '🇷🇴', continent: '欧洲', iso2: 'RO' },
  HUN: { cn: '匈牙利', flag: '🇭🇺', continent: '欧洲', iso2: 'HU' },

  // 南美洲
  BRA: { cn: '巴西', flag: '🇧🇷', continent: '南美洲', iso2: 'BR' },
  ARG: { cn: '阿根廷', flag: '🇦🇷', continent: '南美洲', iso2: 'AR' },
  CHL: { cn: '智利', flag: '🇨🇱', continent: '南美洲', iso2: 'CL' },
  COL: { cn: '哥伦比亚', flag: '🇨🇴', continent: '南美洲', iso2: 'CO' },
  PER: { cn: '秘鲁', flag: '🇵🇪', continent: '南美洲', iso2: 'PE' },
  VEN: { cn: '委内瑞拉', flag: '🇻🇪', continent: '南美洲', iso2: 'VE' },
  ECU: { cn: '厄瓜多尔', flag: '🇪🇨', continent: '南美洲', iso2: 'EC' },
  URY: { cn: '乌拉圭', flag: '🇺🇾', continent: '南美洲', iso2: 'UY' },
  PRY: { cn: '巴拉圭', flag: '🇵🇾', continent: '南美洲', iso2: 'PY' },
  BOL: { cn: '玻利维亚', flag: '🇧🇴', continent: '南美洲', iso2: 'BO' },

  // 大洋洲
  AUS: { cn: '澳大利亚', flag: '🇦🇺', continent: '大洋洲', iso2: 'AU' },
  NZL: { cn: '新西兰', flag: '🇳🇿', continent: '大洋洲', iso2: 'NZ' },

  // 非洲
  ZAF: { cn: '南非', flag: '🇿🇦', continent: '非洲', iso2: 'ZA' },
  EGY: { cn: '埃及', flag: '🇪🇬', continent: '非洲', iso2: 'EG' },
  NGA: { cn: '尼日利亚', flag: '🇳🇬', continent: '非洲', iso2: 'NG' },
  KEN: { cn: '肯尼亚', flag: '🇰🇪', continent: '非洲', iso2: 'KE' },
  MAR: { cn: '摩洛哥', flag: '🇲🇦', continent: '非洲', iso2: 'MA' },
  DZA: { cn: '阿尔及利亚', flag: '🇩🇿', continent: '非洲', iso2: 'DZ' },
  TUN: { cn: '突尼斯', flag: '🇹🇳', continent: '非洲', iso2: 'TN' },
  ETH: { cn: '埃塞俄比亚', flag: '🇪🇹', continent: '非洲', iso2: 'ET' },
  GHA: { cn: '加纳', flag: '🇬🇭', continent: '非洲', iso2: 'GH' },
}

// 大洲排序
export const CONTINENTS = ['北美洲', '亚洲', '中东', '欧洲', '南美洲', '大洋洲', '非洲']

/**
 * 通过国家代码获取信息
 */
export function getCountryInfo(code) {
  if (!code) return { cn: '-', flag: '🌐', continent: '其他', iso2: '' }
  const upper = code.toUpperCase()
  return COUNTRIES[upper] || { cn: upper, flag: '🌐', continent: '其他', iso2: '' }
}

/**
 * 从产品名称中提取城市名（用于 "USA-Seattle" → "Seattle"）
 */
export function extractCityFromName(name, countryCode) {
  if (!name) return ''
  // 去除国家代码前缀
  const countryCn = getCountryInfo(countryCode).cn
  return name
    .replace(new RegExp(`^${countryCode}[-\\s@]`, 'i'), '')
    .replace(new RegExp(`^${countryCn}[-\\s@]`, 'i'), '')
    .replace(/^原生[\s@]?/, '')
    .trim()
}
