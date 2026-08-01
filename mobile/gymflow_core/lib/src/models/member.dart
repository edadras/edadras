/// A club member. Only the fields the apps actually render are parsed.
class Member {
  const Member({
    required this.id,
    required this.code,
    required this.firstName,
    required this.lastName,
    required this.phone,
    this.photoPath,
    this.status = 'active',
    this.qrToken,
    this.gender,
    this.birthDate,
    this.height,
    this.weight,
    this.bloodType,
    this.diseases,
    this.allergies,
  });

  factory Member.fromJson(Map<String, dynamic> json) => Member(
        id: json['id'] as int,
        code: '${json['code'] ?? ''}',
        firstName: json['first_name'] as String? ?? '',
        lastName: json['last_name'] as String? ?? '',
        phone: json['phone'] as String? ?? '',
        photoPath: json['photo_path'] as String?,
        status: json['status'] as String? ?? 'active',
        qrToken: json['qr_token'] as String?,
        gender: json['gender'] as String?,
        birthDate: json['birth_date'] == null ? null : DateTime.tryParse('${json['birth_date']}'),
        height: (json['height'] as num?)?.toInt(),
        weight: double.tryParse('${json['weight']}'),
        bloodType: json['blood_type'] as String?,
        diseases: json['diseases'] as String?,
        allergies: json['allergies'] as String?,
      );

  final int id;
  final String code;
  final String firstName;
  final String lastName;
  final String phone;
  final String? photoPath;
  final String status;
  final String? qrToken;
  final String? gender;
  final DateTime? birthDate;
  final int? height;
  final double? weight;
  final String? bloodType;
  final String? diseases;
  final String? allergies;

  String get fullName => '$firstName $lastName'.trim();

  String get initial => firstName.isEmpty ? '?' : firstName.substring(0, 1);

  bool get isActive => status == 'active';

  /// Anything the reception desk must be warned about before a session.
  List<String> get medicalFlags => [
        if (diseases != null && diseases!.isNotEmpty) diseases!,
        if (allergies != null && allergies!.isNotEmpty) allergies!,
      ];
}
