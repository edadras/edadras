/// A dated occurrence of a class, which is what members actually book. Pool
/// "sans" are the same thing with kind = pool.
class ClassSession {
  const ClassSession({
    required this.id,
    required this.name,
    required this.kind,
    required this.startsAt,
    required this.endsAt,
    required this.capacity,
    required this.bookedCount,
    this.coachName,
    this.price = 0,
    this.status = 'scheduled',
    this.isBooked = false,
  });

  factory ClassSession.fromJson(Map<String, dynamic> json, {String locale = 'fa'}) {
    final gymClass = (json['gym_class'] as Map?)?.cast<String, dynamic>();
    final names = (gymClass?['name'] as Map?)?.cast<String, dynamic>();
    final coach = (json['coach'] as Map?)?.cast<String, dynamic>();

    return ClassSession(
      id: json['id'] as int,
      name: names?[locale] as String? ?? names?['en'] as String? ?? '',
      kind: gymClass?['kind'] as String? ?? 'class',
      startsAt: DateTime.parse('${json['starts_at']}'),
      endsAt: DateTime.parse('${json['ends_at']}'),
      capacity: (json['capacity'] as num?)?.toInt() ?? 0,
      bookedCount: (json['booked_count'] as num?)?.toInt() ?? 0,
      coachName: coach == null ? null : '${coach['first_name']} ${coach['last_name']}'.trim(),
      price: double.tryParse('${json['price']}') ?? 0,
      status: json['status'] as String? ?? 'scheduled',
      isBooked: json['is_booked'] as bool? ?? false,
    );
  }

  final int id;
  final String name;
  final String kind;
  final DateTime startsAt;
  final DateTime endsAt;
  final int capacity;
  final int bookedCount;
  final String? coachName;
  final double price;
  final String status;
  final bool isBooked;

  int get remainingSeats => (capacity - bookedCount).clamp(0, capacity);

  bool get isFull => remainingSeats == 0;

  bool get isBookable =>
      status == 'scheduled' && !isFull && !isBooked && startsAt.isAfter(DateTime.now());

  double get occupancy => capacity == 0 ? 0 : bookedCount / capacity;
}
