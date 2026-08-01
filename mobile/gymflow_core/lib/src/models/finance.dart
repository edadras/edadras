/// A single line of the club's daily cash box.
class CashTransaction {
  const CashTransaction({
    required this.id,
    required this.type,
    required this.category,
    required this.amount,
    required this.occurredAt,
    this.method = 'cash',
    this.description,
  });

  factory CashTransaction.fromJson(Map<String, dynamic> json) => CashTransaction(
        id: json['id'] as int,
        type: json['type'] as String? ?? 'income',
        category: json['category'] as String? ?? 'other',
        amount: double.tryParse('${json['amount']}') ?? 0,
        method: json['method'] as String? ?? 'cash',
        description: json['description'] as String?,
        occurredAt: DateTime.parse('${json['occurred_at']}'),
      );

  final int id;
  final String type;
  final String category;
  final double amount;
  final String method;
  final String? description;
  final DateTime occurredAt;

  bool get isIncome => type == 'income';
}

/// The till a cashier reconciles at the end of a shift.
class DailyRegister {
  const DailyRegister({
    required this.date,
    required this.income,
    required this.expense,
    required this.net,
    required this.byMethod,
    required this.transactions,
  });

  factory DailyRegister.fromJson(Map<String, dynamic> json) => DailyRegister(
        date: '${json['date']}',
        income: double.tryParse('${json['income']}') ?? 0,
        expense: double.tryParse('${json['expense']}') ?? 0,
        net: double.tryParse('${json['net']}') ?? 0,
        byMethod: ((json['by_method'] as Map?) ?? const {}).map(
          (key, value) => MapEntry('$key', double.tryParse('$value') ?? 0),
        ),
        transactions: ((json['transactions'] as List?) ?? const [])
            .cast<Map>()
            .map((row) => CashTransaction.fromJson(row.cast<String, dynamic>()))
            .toList(),
      );

  final String date;
  final double income;
  final double expense;
  final double net;
  final Map<String, double> byMethod;
  final List<CashTransaction> transactions;
}

/// A shop item on the point of sale grid.
class Product {
  const Product({
    required this.id,
    required this.name,
    required this.price,
    required this.stock,
    this.category = 'supplement',
    this.minStock = 0,
  });

  factory Product.fromJson(Map<String, dynamic> json) => Product(
        id: json['id'] as int,
        name: json['name'] as String? ?? '',
        price: double.tryParse('${json['price']}') ?? 0,
        stock: (json['stock'] as num?)?.toInt() ?? 0,
        category: json['category'] as String? ?? 'supplement',
        minStock: (json['min_stock'] as num?)?.toInt() ?? 0,
      );

  final int id;
  final String name;
  final double price;
  final int stock;
  final String category;
  final int minStock;

  bool get isLowStock => stock <= minStock;

  bool get isSellable => stock > 0;
}

/// One line of the basket at the counter.
class BasketLine {
  BasketLine({required this.product, this.quantity = 1});

  final Product product;
  int quantity;

  double get total => product.price * quantity;
}

/// An invoice, as the member's payment history shows it.
class Invoice {
  const Invoice({
    required this.id,
    required this.number,
    required this.total,
    required this.paidAmount,
    required this.status,
    required this.issuedAt,
    this.items = const [],
  });

  factory Invoice.fromJson(Map<String, dynamic> json) => Invoice(
        id: json['id'] as int,
        number: json['number'] as String? ?? '',
        total: double.tryParse('${json['total']}') ?? 0,
        paidAmount: double.tryParse('${json['paid_amount']}') ?? 0,
        status: json['status'] as String? ?? 'unpaid',
        issuedAt: DateTime.parse('${json['issued_at']}'),
        items: ((json['items'] as List?) ?? const [])
            .cast<Map>()
            .map((row) => '${row['description']}')
            .toList(),
      );

  final int id;
  final String number;
  final double total;
  final double paidAmount;
  final String status;
  final DateTime issuedAt;
  final List<String> items;

  double get balance => total - paidAmount;

  bool get isPaid => status == 'paid';
}

/// A movement of the member's in-app credit.
class WalletEntry {
  const WalletEntry({
    required this.id,
    required this.type,
    required this.amount,
    required this.balanceAfter,
    required this.createdAt,
    this.description,
  });

  factory WalletEntry.fromJson(Map<String, dynamic> json) => WalletEntry(
        id: json['id'] as int,
        type: json['type'] as String? ?? 'credit',
        amount: double.tryParse('${json['amount']}') ?? 0,
        balanceAfter: double.tryParse('${json['balance_after']}') ?? 0,
        description: json['description'] as String?,
        createdAt: DateTime.parse('${json['created_at']}'),
      );

  final int id;
  final String type;
  final double amount;
  final double balanceAfter;
  final String? description;
  final DateTime createdAt;

  bool get isCredit => type == 'credit';
}
