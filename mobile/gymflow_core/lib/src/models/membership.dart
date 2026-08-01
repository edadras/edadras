/// A purchased membership. Both limits apply at once: a session pass with a
/// deadline expires on whichever runs out first.
class Membership {
  const Membership({
    required this.id,
    required this.type,
    required this.status,
    this.planName,
    this.startsAt,
    this.endsAt,
    this.totalSessions,
    this.remainingSessions,
    this.price = 0,
  });

  factory Membership.fromJson(Map<String, dynamic> json, {String locale = 'fa'}) {
    final plan = json['plan'] as Map?;
    final names = (plan?['name'] as Map?)?.cast<String, dynamic>();

    return Membership(
      id: json['id'] as int,
      type: json['type'] as String? ?? 'duration',
      status: json['status'] as String? ?? 'active',
      planName: names?[locale] as String? ?? names?['en'] as String?,
      startsAt: json['starts_at'] == null ? null : DateTime.tryParse('${json['starts_at']}'),
      endsAt: json['ends_at'] == null ? null : DateTime.tryParse('${json['ends_at']}'),
      totalSessions: (json['total_sessions'] as num?)?.toInt(),
      remainingSessions: (json['remaining_sessions'] as num?)?.toInt(),
      price: double.tryParse('${json['price']}') ?? 0,
    );
  }

  final int id;
  final String type;
  final String status;
  final String? planName;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final int? totalSessions;
  final int? remainingSessions;
  final double price;

  bool get isActive => status == 'active';

  int? get daysRemaining {
    if (endsAt == null) return null;

    final today = DateTime.now();
    final days = endsAt!.difference(DateTime(today.year, today.month, today.day)).inDays;

    return days < 0 ? 0 : days;
  }

  /// How much of the pass is left, for the ring on the member's home screen.
  double get progress {
    if (totalSessions != null && totalSessions! > 0) {
      return (remainingSessions ?? 0) / totalSessions!;
    }

    if (startsAt == null || endsAt == null) return 1;

    final total = endsAt!.difference(startsAt!).inDays;
    if (total <= 0) return 0;

    return ((daysRemaining ?? 0) / total).clamp(0, 1).toDouble();
  }
}
