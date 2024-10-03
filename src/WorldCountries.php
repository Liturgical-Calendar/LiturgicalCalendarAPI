<?php

namespace LiturgicalCalendar\Api;

class WorldCountries
{
    /**
     * ISO 3166-1 alpha-2
     * Standardized country codes
     * There are currently 249 registered country codes in the standard
     *
     * @see https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2
     */
    public const array ISO_3166_1_ALPHA_2 = [
        "AD", "AE", "AF", "AG", "AI", "AL", "AM", "AO", "AQ", "AR", "AS", "AT", "AU", "AW", "AX", "AZ", // 16
        "BA", "BB", "BD", "BE", "BF", "BG", "BH", "BI", "BJ", "BL", "BM", "BN", "BO", "BQ", "BR", "BS", // 32
        "BT", "BV", "BW", "BY", "BZ", "CA", "CC", "CD", "CF", "CG", "CH", "CI", "CK", "CL", "CM", "CN", // 48
        "CO", "CR", "CU", "CV", "CW", "CX", "CY", "CZ", "DE", "DJ", "DK", "DM", "DO", "DZ", "EC", "EE", // 64
        "EG", "EH", "ER", "ES", "ET", "FI", "FJ", "FK", "FM", "FO", "FR", "GA", "GB", "GD", "GE", "GF", // 80
        "GG", "GH", "GI", "GL", "GM", "GN", "GP", "GQ", "GR", "GS", "GT", "GU", "GW", "GY", "HK", "HM", // 96
        "HN", "HR", "HT", "HU", "ID", "IE", "IL", "IM", "IN", "IO", "IQ", "IR", "IS", "IT", "JE", "JM", // 112
        "JO", "JP", "KE", "KG", "KH", "KI", "KM", "KN", "KP", "KR", "KW", "KY", "KZ", "LA", "LB", "LC", // 128
        "LI", "LK", "LR", "LS", "LT", "LU", "LV", "LY", "MA", "MC", "MD", "ME", "MF", "MG", "MH", "MK", // 144
        "ML", "MM", "MN", "MO", "MP", "MQ", "MR", "MS", "MT", "MU", "MV", "MW", "MX", "MY", "MZ", "NA", // 160
        "NC", "NE", "NF", "NG", "NI", "NL", "NO", "NP", "NR", "NU", "NZ", "OM", "PA", "PE", "PF", "PG", // 176
        "PH", "PK", "PL", "PM", "PN", "PR", "PS", "PT", "PW", "PY", "QA", "RE", "RO", "RS", "RU", "RW", // 192
        "SA", "SB", "SC", "SD", "SE", "SG", "SH", "SI", "SJ", "SK", "SL", "SM", "SN", "SO", "SR", "SS", // 208
        "ST", "SV", "SX", "SY", "SZ", "TC", "TD", "TF", "TG", "TH", "TJ", "TK", "TL", "TM", "TN", "TO", // 224
        "TR", "TT", "TV", "TW", "TZ", "UA", "UG", "UM", "US", "UY", "UZ", "VA", "VC", "VE", "VG", "VI", // 240
        "VN", "VU", "WF", "WS", "YE", "YT", "ZA", "ZM", "ZW"                                            // 249
    ];

    /**
     * Returns the English name of a country given its ISO 3166-1 alpha-2 code.
     *
     * @param string $iso ISO 3166-1 alpha-2 code of a country
     * @return string English name of the given country
     * Iterating over all ISO values with the isoToCountry method should result in the following list of countries:
     *
     * 1. Andorra
     * 2. United Arab Emirates
     * 3. Afghanistan
     * 4. Antigua & Barbuda
     * 5. Anguilla
     * 6. Albania
     * 7. Armenia
     * 8. Angola
     * 9. Antarctica
     * 10. Argentina
     * 11. American Samoa
     * 12. Austria
     * 13. Australia
     * 14. Aruba
     * 15. Åland Islands
     * 16. Azerbaijan
     * 17. Bosnia & Herzegovina
     * 18. Barbados
     * 19. Bangladesh
     * 20. Belgium
     * 21. Burkina Faso
     * 22. Bulgaria
     * 23. Bahrain
     * 24. Burundi
     * 25. Benin
     * 26. St. Barthélemy
     * 27. Bermuda
     * 28. Brunei
     * 29. Bolivia
     * 30. Caribbean Netherlands
     * 31. Brazil
     * 32. Bahamas
     * 33. Bhutan
     * 34. Bouvet Island
     * 35. Botswana
     * 36. Belarus
     * 37. Belize
     * 38. Canada
     * 39. Cocos (Keeling) Islands
     * 40. Congo - Kinshasa
     * 41. Central African Republic
     * 42. Congo - Brazzaville
     * 43. Switzerland
     * 44. Côte d’Ivoire
     * 45. Cook Islands
     * 46. Chile
     * 47. Cameroon
     * 48. China
     * 49. Colombia
     * 50. Costa Rica
     * 51. Cuba
     * 52. Cape Verde
     * 53. Curaçao
     * 54. Christmas Island
     * 55. Cyprus
     * 56. Czechia
     * 57. Germany
     * 58. Djibouti
     * 59. Denmark
     * 60. Dominica
     * 61. Dominican Republic
     * 62. Algeria
     * 63. Ecuador
     * 64. Estonia
     * 65. Egypt
     * 66. Western Sahara
     * 67. Eritrea
     * 68. Spain
     * 69. Ethiopia
     * 70. Finland
     * 71. Fiji
     * 72. Falkland Islands
     * 73. Micronesia
     * 74. Faroe Islands
     * 75. France
     * 76. Gabon
     * 77. United Kingdom
     * 78. Grenada
     * 79. Georgia
     * 80. French Guiana
     * 81. Guernsey
     * 82. Ghana
     * 83. Gibraltar
     * 84. Greenland
     * 85. Gambia
     * 86. Guinea
     * 87. Guadeloupe
     * 88. Equatorial Guinea
     * 89. Greece
     * 90. South Georgia & South Sandwich Islands
     * 91. Guatemala
     * 92. Guam
     * 93. Guinea-Bissau
     * 94. Guyana
     * 95. Hong Kong SAR China
     * 96. Heard & McDonald Islands
     * 97. Honduras
     * 98. Croatia
     * 99. Haiti
     * 100. Hungary
     * 101. Indonesia
     * 102. Ireland
     * 103. Israel
     * 104. Isle of Man
     * 105. India
     * 106. British Indian Ocean Territory
     * 107. Iraq
     * 108. Iran
     * 109. Iceland
     * 110. Italy
     * 111. Jersey
     * 112. Jamaica
     * 113. Jordan
     * 114. Japan
     * 115. Kenya
     * 116. Kyrgyzstan
     * 117. Cambodia
     * 118. Kiribati
     * 119. Comoros
     * 120. St. Kitts & Nevis
     * 121. North Korea
     * 122. South Korea
     * 123. Kuwait
     * 124. Cayman Islands
     * 125. Kazakhstan
     * 126. Laos
     * 127. Lebanon
     * 128. St. Lucia
     * 129. Liechtenstein
     * 130. Sri Lanka
     * 131. Liberia
     * 132. Lesotho
     * 133. Lithuania
     * 134. Luxembourg
     * 135. Latvia
     * 136. Libya
     * 137. Morocco
     * 138. Monaco
     * 139. Moldova
     * 140. Montenegro
     * 141. St. Martin
     * 142. Madagascar
     * 143. Marshall Islands
     * 144. North Macedonia
     * 145. Mali
     * 146. Myanmar (Burma)
     * 147. Mongolia
     * 148. Macao SAR China
     * 149. Northern Mariana Islands
     * 150. Martinique
     * 151. Mauritania
     * 152. Montserrat
     * 153. Malta
     * 154. Mauritius
     * 155. Maldives
     * 156. Malawi
     * 157. Mexico
     * 158. Malaysia
     * 159. Mozambique
     * 160. Namibia
     * 161. New Caledonia
     * 162. Niger
     * 163. Norfolk Island
     * 164. Nigeria
     * 165. Nicaragua
     * 166. Netherlands
     * 167. Norway
     * 168. Nepal
     * 169. Nauru
     * 170. Niue
     * 171. New Zealand
     * 172. Oman
     * 173. Panama
     * 174. Peru
     * 175. French Polynesia
     * 176. Papua New Guinea
     * 177. Philippines
     * 178. Pakistan
     * 179. Poland
     * 180. St. Pierre & Miquelon
     * 181. Pitcairn Islands
     * 182. Puerto Rico
     * 183. Palestinian Territories
     * 184. Portugal
     * 185. Palau
     * 186. Paraguay
     * 187. Qatar
     * 188. Réunion
     * 189. Romania
     * 190. Serbia
     * 191. Russia
     * 192. Rwanda
     * 193. Saudi Arabia
     * 194. Solomon Islands
     * 195. Seychelles
     * 196. Sudan
     * 197. Sweden
     * 198. Singapore
     * 199. St. Helena
     * 200. Slovenia
     * 201. Svalbard & Jan Mayen
     * 202. Slovakia
     * 203. Sierra Leone
     * 204. San Marino
     * 205. Senegal
     * 206. Somalia
     * 207. Suriname
     * 208. South Sudan
     * 209. São Tomé & Príncipe
     * 210. El Salvador
     * 211. Sint Maarten
     * 212. Syria
     * 213. Eswatini
     * 214. Turks & Caicos Islands
     * 215. Chad
     * 216. French Southern Territories
     * 217. Togo
     * 218. Thailand
     * 219. Tajikistan
     * 220. Tokelau
     * 221. Timor-Leste
     * 222. Turkmenistan
     * 223. Tunisia
     * 224. Tonga
     * 225. Turkey
     * 226. Trinidad & Tobago
     * 227. Tuvalu
     * 228. Taiwan
     * 229. Tanzania
     * 230. Ukraine
     * 231. Uganda
     * 232. U.S. Outlying Islands
     * 233. United States
     * 234. Uruguay
     * 235. Uzbekistan
     * 236. Vatican City
     * 237. St. Vincent & Grenadines
     * 238. Venezuela
     * 239. British Virgin Islands
     * 240. U.S. Virgin Islands
     * 241. Vietnam
     * 242. Vanuatu
     * 243. Wallis & Futuna
     * 244. Samoa
     * 245. Yemen
     * 246. Mayotte
     * 247. South Africa
     * 248. Zambia
     * 249. Zimbabwe
     */
    public static function isoToCountry(string $iso): string
    {
        return \Locale::getDisplayRegion('-' . $iso, 'en');
    }
}
