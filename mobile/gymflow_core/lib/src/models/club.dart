/// A club: gym, pool, martial arts hall, yoga or pilates studio.
class Club {
  const Club({
    required this.id,
    required this.slug,
    required this.name,
    required this.type,
    this.logoPath,
    this.brandColor = '#5EF38C',
    this.phone,
    this.address,
    this.currency = 'IRR',
    this.locale = 'fa',
    this.socials = const {},
    this.workingHours = const {},
    this.rules,
  });

  factory Club.fromJson(Map<String, dynamic> json) => Club(
        id: json['id'] as int,
        slug: json['slug'] as String,
        name: json['name'] as String,
        type: json['type'] as String? ?? 'gym',
        logoPath: json['logo_path'] as String?,
        brandColor: json['brand_color'] as String? ?? '#5EF38C',
        phone: json['phone'] as String?,
        address: json['address'] as String?,
        currency: json['currency'] as String? ?? 'IRR',
        locale: json['locale'] as String? ?? 'fa',
        socials: (json['socials'] as Map?)?.cast<String, dynamic>() ?? const {},
        workingHours: (json['working_hours'] as Map?)?.cast<String, dynamic>() ?? const {},
        rules: json['rules'] as String?,
      );

  final int id;
  final String slug;
  final String name;
  final String type;
  final String? logoPath;
  final String brandColor;
  final String? phone;
  final String? address;
  final String currency;
  final String locale;
  final Map<String, dynamic> socials;
  final Map<String, dynamic> workingHours;
  final String? rules;
}
