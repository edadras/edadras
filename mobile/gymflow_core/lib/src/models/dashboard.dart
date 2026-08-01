/// The manager app's home screen figures, straight from /dashboard.
class Dashboard {
  const Dashboard({
    required this.totalMembers,
    required this.activeMembers,
    required this.checkInsToday,
    required this.insideNow,
    required this.revenueToday,
    required this.revenueMonth,
    required this.activeMemberships,
    required this.expiringSoon,
    required this.sessionsToday,
    required this.revenueSeries,
    required this.attendanceSeries,
  });

  factory Dashboard.fromJson(Map<String, dynamic> json) {
    final members = (json['members'] as Map).cast<String, dynamic>();
    final attendance = (json['attendance'] as Map).cast<String, dynamic>();
    final revenue = (json['revenue'] as Map).cast<String, dynamic>();
    final memberships = (json['memberships'] as Map).cast<String, dynamic>();
    final classes = (json['classes'] as Map).cast<String, dynamic>();
    final charts = (json['charts'] as Map).cast<String, dynamic>();

    return Dashboard(
      totalMembers: (members['total'] as num).toInt(),
      activeMembers: (members['active'] as num).toInt(),
      checkInsToday: (attendance['checkins_today'] as num).toInt(),
      insideNow: (attendance['inside_now'] as num).toInt(),
      revenueToday: double.tryParse('${revenue['today']}') ?? 0,
      revenueMonth: double.tryParse('${revenue['month']}') ?? 0,
      activeMemberships: (memberships['active'] as num).toInt(),
      expiringSoon: (memberships['expiring_7_days'] as num).toInt(),
      sessionsToday: (classes['sessions_today'] as num).toInt(),
      revenueSeries: SeriesPoint.listFrom(charts['revenue_last_30_days']),
      attendanceSeries: SeriesPoint.listFrom(charts['attendance_last_30_days']),
    );
  }

  final int totalMembers;
  final int activeMembers;
  final int checkInsToday;
  final int insideNow;
  final double revenueToday;
  final double revenueMonth;
  final int activeMemberships;
  final int expiringSoon;
  final int sessionsToday;
  final List<SeriesPoint> revenueSeries;
  final List<SeriesPoint> attendanceSeries;
}

/// One point on a chart: a day and its total.
class SeriesPoint {
  const SeriesPoint(this.label, this.value);

  static List<SeriesPoint> listFrom(dynamic raw) {
    if (raw is! List) return const [];

    return raw
        .cast<Map>()
        .map((point) => SeriesPoint(
              '${point['date'] ?? point['hour'] ?? ''}',
              double.tryParse('${point['total'] ?? point['value'] ?? 0}') ?? 0,
            ))
        .toList();
  }

  final String label;
  final double value;
}
