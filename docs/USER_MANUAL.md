# User manual

PrintBoss is organised into nine pages, listed in the sidebar on the left. This manual walks through a normal working week: set up, quote, print, sell, invoice.

## 12.1 First time: settings

Go to **Settings**. Fill in your business name, ABN, address and bank details; these print on every invoice. Set the invoice prefix (for example `INV`) and your GST rate (10 % in Australia). Under **Calculator defaults** enter your electricity price per kWh, your hourly labour rate, the profit margin you aim for and a failed-print allowance (10 % is a sensible start). Click **Save settings**.



## 12.2 Add your printers and filament

On **Printers** click **+ Add printer**. Enter the name, model, purchase price and the number of hours you expect it to last (5,000 is typical for a hobby FDM printer). PrintBoss divides price by hours to work out depreciation per hour, which feeds the calculator. Average power in watts matters too; 150 to 200 W is normal for an FDM machine.

On **Filament** click **+ Add roll** for each spool: material, colour, brand, price paid, total and remaining weight. Set a low-stock alert in grams. The page shows a bar for each roll and the cost per kilogram, which the calculator can load with one click.



## 12.3 Add customers

**Customers** holds your buyers. Click **+ Add customer**, enter at least a name, and add email, phone and address if you will invoice them. The table shows how many orders each customer has placed and their lifetime value. **Orders** on a customer row filters the Orders page to that customer.

## 12.4 Work out the real cost and quote

Open **Cost Calculator**. Type the product name and, if you like, pick a filament roll and a printer at the top; their cost per kg, power and depreciation drop into the fields. Enter grams used, print time and labour time. Everything on the right updates as you type: material, electricity, depreciation, labour and packaging costs, the failed-print buffer, the **total real cost**, and the **suggested selling price** for your margin.

Then choose one or both actions:

- **Save as quote** creates an order with status Quote for the customer you pick. Set the quantity first if it is more than one.
- **Create stock product** turns the calculation into a product in Stock with the cost and price filled in and quantity 0. If a product with the same name exists, PrintBoss warns you.



## 12.5 Run a print job

On **Printers**, click **Start print job** on an available printer. Choose what you are printing:

- **Stock product (restock)**: pick the product. Successful units are added to stock when you confirm.
- **Customer order**: pick the order. Quantity, material, grams and time are filled from the order, and the order moves to Printing.
- **Custom / one-off**: give the job a name.

Enter the planned quantity, estimated minutes and material. If you pick a filament roll, PrintBoss deducts the grams (plus any waste) when the job is confirmed. Click **Start job**. The printer card shows a countdown; it turns red if the print runs over. Use **Pause** and **Resume** as needed. When the print is off the bed, click **Finish**, then **Confirm result**: enter how many units came out well, any wasted grams and a reason for failures. Only the good units go into stock. Partial failures are recorded against the job so you can see which products fail most.

Click **Service** on a printer to log maintenance (nozzle change, bed cleaning and so on) with a cost and the next due date. A printer in Maintenance returns to Available when you save the log.



## 12.6 Manage stock

**Stock** lists finished products with quantity, status, cost, price and margin. Use the green **+** to add units (for prints you did not run through a job) and the red **minus** to remove them with a reason. PrintBoss will not let you remove more than you have. The status changes on its own: Out of Stock at zero, Low Stock at or below your minimum, otherwise In Stock. Set a product to Inactive to hide it from quotes. The **Recent stock movements** table at the bottom is your audit trail: every change, who or what caused it, before and after.



## 12.7 Orders

**Orders** shows every quote and order. Change the status straight from the dropdown in the table: Quote, Approved, Printing, Post-processing, Ready, Delivered or Cancelled. If the order is linked to a stock product, the quantity is reserved the moment the order leaves Quote and returned if you cancel. Click **Edit** to change the customer, product, quantity, prices or delivery date; totals update as you type. Click **Invoice** on a row to bill it.



## 12.8 Invoices

**Invoices** lists every invoice with its balance. **+ New invoice** opens a blank form; **Invoice** on an order opens a prefilled one. Pick a customer to copy their contact details, add lines (typing a stock product name fills its price), apply a discount and GST, and record anything already paid. Totals update live. Click **Save invoice** to open the printable tax invoice; **Print / PDF** uses your browser's print dialog, with the sidebar hidden.

Back in the list: **Mark sent** when you email it, **Payment** to record a partial payment, **Mark paid** when the balance is settled. Sent invoices past their due date are flagged Overdue automatically.



## 12.9 Dashboard

The **Dashboard** is where you start each day: revenue and profit this month, active orders, average margin, outstanding invoices, printer status, jobs in progress, printers due for service and low material. Two charts show six months of revenue, profit and order counts. Alerts link to the page where you fix them.

## 12.10 Your account and privacy

In **Settings** you can change your password at any time. Under **Danger zone** you can delete your account; this removes every record you own immediately and permanently. The privacy notice linked in the footer explains what PrintBoss stores and why.
