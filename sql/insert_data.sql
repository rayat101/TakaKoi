-- Branch
INSERT INTO Branch (branch_name, address, phone)
VALUES ('TakaKoi Main Branch', 'House 12, Road 5, Kafrul, Dhaka', '01711000000');

-- Some admins
INSERT INTO Employee (employee_id, first_name, last_name, salary, email, username, password, employee_type, branch_id) VALUES 
(10000001, 'Admin', 'One', 50000.00, 'admin1@takakoi.com', 'admin1', '2y12$VV.2dbGyK3wnouyKxO6c8ePvq8S.YiTqchoSBOmIdcF5P9r9dNFsO', 'Admin', 1), 
(10000002, 'Admin', 'Two', 50000.00, 'admin2@takakoi.com', 'admin2', '2y12$hLvLjEqUKjvVmrzIBYpHiO8Jlel6WwIwZFdqL2/uxQD1d12fQl5Ay', 'Admin', 1), 
(10000003, 'Admin', 'Three', 50000.00, 'admin3@takakoi.com', 'admin3', '2y12$36zM5RbSpQ7s64Yplkp.JuRetMtzKELeBtC3RWkPtaTsEueoWagxe', 'Admin', 1);
