--  TakaKoi - Banking Management System
--  Database Schema

CREATE DATABASE Banking_software;
USE Banking_software;


-- 1. Branch

CREATE TABLE Branch (
    branch_id     INT AUTO_INCREMENT,
    branch_name   VARCHAR(40)  NOT NULL,
    address       VARCHAR(100) NOT NULL,
    phone         VARCHAR(11)  NOT NULL,

    PRIMARY KEY (branch_id),
    UNIQUE (branch_name),
    UNIQUE (phone)
) ENGINE = InnoDB;


-- 2. Employee

CREATE TABLE Employee (
    employee_id     INT,
    first_name      VARCHAR(30)  NOT NULL,
    last_name       VARCHAR(30)  NOT NULL,
    salary          DECIMAL(15,2) NOT NULL,
    email           VARCHAR(100) NOT NULL,
    username        VARCHAR(30)  NOT NULL,
    password        VARCHAR(255) NOT NULL,
    employee_type   ENUM('Admin', 'Manager', 'Loan Officer') NOT NULL,
    branch_id       INT NOT NULL,

    PRIMARY KEY (employee_id),
    UNIQUE (email),
    UNIQUE (username),

    CONSTRAINT chk_employee_id_8digit
        CHECK (employee_id BETWEEN 10000000 AND 99999999),

    CONSTRAINT fk_employee_branch
        FOREIGN KEY (branch_id) REFERENCES Branch(branch_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;


-- 3. Customer

CREATE TABLE Customer (
    customer_id   INT,
    username      VARCHAR(30)  NOT NULL,
    password      VARCHAR(255) NOT NULL,
    first_name    VARCHAR(30)  NOT NULL,
    last_name     VARCHAR(30)  NOT NULL,
    email         VARCHAR(100) NOT NULL,
    house_no      VARCHAR(20)  NOT NULL,
    area          VARCHAR(30)  NOT NULL,
    district      VARCHAR(30)  NOT NULL,

    PRIMARY KEY (customer_id),
    UNIQUE (username),
    UNIQUE (email),

    CONSTRAINT chk_customer_id_8digit
        CHECK (customer_id BETWEEN 10000000 AND 99999999)
) ENGINE = InnoDB;


-- 4. Customer_phone (weak entity)

CREATE TABLE Customer_phone (
    customer_id   INT,
    phone         VARCHAR(11) NOT NULL,

    PRIMARY KEY (customer_id, phone),

    CONSTRAINT chk_phone_11digit
        CHECK (LENGTH(phone) = 11 AND phone REGEXP '^[0-9]{11}$'),

    CONSTRAINT fk_customer_phone_customer
        FOREIGN KEY (customer_id) REFERENCES Customer(customer_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;


-- 5. Customer_beneficiary (weak entity)

CREATE TABLE Customer_beneficiary (
    customer_id   INT,
    account_id    INT NOT NULL,
    name          VARCHAR(50) NOT NULL,

    PRIMARY KEY (customer_id, account_id),

    CONSTRAINT chk_beneficiary_account_10digit
        CHECK (account_id BETWEEN 1000000000 AND 2147483647),

    CONSTRAINT fk_beneficiary_customer
        FOREIGN KEY (customer_id) REFERENCES Customer(customer_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;


-- 6. Account

CREATE TABLE Account (
    account_id     INT,
    balance        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    opening_date   DATE NOT NULL,
    status         ENUM('Active', 'Suspended', 'Closed') NOT NULL,
    account_type   ENUM('Saving', 'Current') NOT NULL,
    closed_date    DATE NULL,
    customer_id    INT NOT NULL,
    branch_id      INT NOT NULL,

    PRIMARY KEY (account_id),

    CONSTRAINT chk_account_id_10digit
        CHECK (account_id BETWEEN 1000000000 AND 2147483647),

    -- closed_date is required only when the account is closed
    CONSTRAINT chk_closed_date_on_closed
        CHECK (status <> 'Closed' OR closed_date IS NOT NULL),

    CONSTRAINT fk_account_customer
        FOREIGN KEY (customer_id) REFERENCES Customer(customer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_account_branch
        FOREIGN KEY (branch_id) REFERENCES Branch(branch_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;


-- 7. Loan

CREATE TABLE Loan (
    loan_id         INT,
    loan_type       VARCHAR(30) NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    interest_rate   DECIMAL(5,2) NOT NULL,
    duration        INT NOT NULL,
    start_date      DATE NOT NULL,
    status          ENUM('Pending', 'Approved', 'Active', 'Paid', 'Defaulted', 'Rejected') NOT NULL,
    reason          VARCHAR(500) NOT NULL,
    customer_id     INT NOT NULL,
    employee_id     INT NOT NULL,

    PRIMARY KEY (loan_id),

    CONSTRAINT chk_loan_id_6digit
        CHECK (loan_id BETWEEN 100000 AND 999999),
    CONSTRAINT chk_loan_amount_positive
        CHECK (amount > 0),
    CONSTRAINT chk_loan_interest_positive
        CHECK (interest_rate > 0),
    CONSTRAINT chk_loan_duration_positive
        CHECK (duration > 0),

    CONSTRAINT fk_loan_customer
        FOREIGN KEY (customer_id) REFERENCES Customer(customer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_loan_employee
        FOREIGN KEY (employee_id) REFERENCES Employee(employee_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;


-- 8. Loan_payment

CREATE TABLE Loan_payment (
    payment_id       INT AUTO_INCREMENT,
    amount           DECIMAL(15,2) NOT NULL,
    payment_date     DATE NOT NULL,
    payment_method   ENUM('Cash', 'Bank Transfer', 'Card', 'Cheque') NOT NULL,
    loan_id          INT NOT NULL,

    PRIMARY KEY (payment_id),

    CONSTRAINT chk_loan_payment_amount_positive
        CHECK (amount > 0),

    CONSTRAINT fk_loan_payment_loan
        FOREIGN KEY (loan_id) REFERENCES Loan(loan_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;


-- 9. Transaction

CREATE TABLE Transaction (
    transaction_id     INT,
    transaction_type   ENUM('Deposit', 'Withdrawal', 'Transfer') NOT NULL,
    amount             DECIMAL(15,2) NOT NULL,
    t_date             DATE NOT NULL,
    t_time             TIME NOT NULL,
    description        VARCHAR(200) NOT NULL,
    sent_to_id         INT NULL,
    account_id         INT NOT NULL,

    PRIMARY KEY (transaction_id),

    CONSTRAINT chk_transaction_id_7digit
        CHECK (transaction_id BETWEEN 1000000 AND 9999999),
    CONSTRAINT chk_transaction_amount_positive
        CHECK (amount > 0),

    -- sent_to_id is required only for transfers
    CONSTRAINT chk_sent_to_required_on_transfer
        CHECK (transaction_type <> 'Transfer' OR sent_to_id IS NOT NULL),

    CONSTRAINT fk_transaction_account
        FOREIGN KEY (account_id) REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_transaction_sent_to
        FOREIGN KEY (sent_to_id) REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;


-- 10. Fraud

CREATE TABLE Fraud (
    alert_id         INT,
    reason           VARCHAR(200) NOT NULL,
    alert_date       DATE NOT NULL,
    status           ENUM('Pending', 'Investigating', 'Confirmed', 'Dismissed') NOT NULL,
    transaction_id   INT NOT NULL,

    PRIMARY KEY (alert_id),
    UNIQUE (transaction_id),   -- enforces 1:1 with Transaction

    CONSTRAINT chk_alert_id_8digit
        CHECK (alert_id BETWEEN 10000000 AND 99999999),

    CONSTRAINT fk_fraud_transaction
        FOREIGN KEY (transaction_id) REFERENCES Transaction(transaction_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;



-- 11. Closure_request

CREATE TABLE Closure_request (
    request_id      INT AUTO_INCREMENT,
    reason          VARCHAR(200) NOT NULL,
    request_date    DATE NOT NULL,
    status          ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    decision_date   DATE NULL,
    decision_note   VARCHAR(200) NULL,
    account_id      INT NOT NULL,
    customer_id     INT NOT NULL,
    handled_by      INT NULL,

    PRIMARY KEY (request_id),

    -- A decided request must record who handled it and when
    CONSTRAINT chk_closure_handled_by_on_decision
        CHECK (status = 'Pending' OR handled_by IS NOT NULL),
    CONSTRAINT chk_closure_date_on_decision
        CHECK (status = 'Pending' OR decision_date IS NOT NULL),

    CONSTRAINT fk_closure_account
        FOREIGN KEY (account_id) REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_closure_customer
        FOREIGN KEY (customer_id) REFERENCES Customer(customer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_closure_employee
        FOREIGN KEY (handled_by) REFERENCES Employee(employee_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;


-- 12. Cheque_book

CREATE TABLE Cheque_book (
    book_id        INT AUTO_INCREMENT,
    total_leaves   INT NOT NULL DEFAULT 25,
    request_date   DATE NOT NULL,
    issue_date     DATE NULL,
    status         ENUM('Requested', 'Issued', 'Rejected', 'Exhausted') NOT NULL DEFAULT 'Requested',
    account_id     INT NOT NULL,
    issued_by      INT NULL,

    PRIMARY KEY (book_id),

    CONSTRAINT chk_cheque_book_leaves_positive
        CHECK (total_leaves > 0),

    -- An issued book must have an issue date
    CONSTRAINT chk_cheque_book_issue_date
        CHECK (status <> 'Issued' OR issue_date IS NOT NULL),

    CONSTRAINT fk_cheque_book_account
        FOREIGN KEY (account_id) REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_cheque_book_employee
        FOREIGN KEY (issued_by) REFERENCES Employee(employee_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;


-- 13. Cheque (weak entity - identified by Cheque_book)

CREATE TABLE Cheque (
    book_id        INT,
    cheque_no      INT,
    amount         DECIMAL(15,2) NULL,
    issued_to      VARCHAR(50) NULL,
    cheque_date    DATE NULL,
    status         ENUM('Unused', 'Issued', 'Cleared', 'Bounced', 'Cancelled') NOT NULL DEFAULT 'Unused',
    transaction_id INT NULL,

    PRIMARY KEY (book_id, cheque_no),
    UNIQUE (transaction_id),   -- one cheque maps to at most one transaction

    CONSTRAINT chk_cheque_no_positive
        CHECK (cheque_no > 0),
    CONSTRAINT chk_cheque_amount_positive
        CHECK (amount IS NULL OR amount > 0),

    -- An unused cheque should not carry payment details
    CONSTRAINT chk_cheque_unused_is_blank
        CHECK (status <> 'Unused' OR (amount IS NULL AND issued_to IS NULL)),

    CONSTRAINT fk_cheque_book
        FOREIGN KEY (book_id) REFERENCES Cheque_book(book_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_cheque_transaction
        FOREIGN KEY (transaction_id) REFERENCES Transaction(transaction_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;
